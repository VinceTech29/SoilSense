<?php

namespace Tests\Feature;

use App\Models\FertilizerRecommendation;
use App\Models\IotDevice;
use App\Models\SensorReading;
use App\Models\SoilSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FertilizerIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;
    protected string $validToken = 'demo-esp32-token-2025';
    protected string $deviceUid = 'ESP32-01';
    protected string $sampleCode = 'SS-2025-018';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('fertilizer.ml_service', [
            'url' => 'http://ml.soilsense.test',
            'api_key' => 'test-ml-api-key',
            'timeout_seconds' => 5,
            'retry_times' => 0,
            'low_confidence_threshold' => 0.55,
        ]);

        Config::set('fertilizer.rain_guard.open_meteo_url', 'https://api.open-meteo.com/v1/forecast');

        Http::fake([
            'http://ml.soilsense.test/predict' => Http::response([
                'predicted_fertilizer' => 'Urea',
                'confidence' => 0.89,
                'low_confidence' => false,
                'top_predictions' => [
                    ['fertilizer' => 'Urea', 'probability' => 0.89],
                    ['fertilizer' => 'NPK', 'probability' => 0.08],
                    ['fertilizer' => 'DAP', 'probability' => 0.03],
                ],
                'model_version' => 'gb-v1.0',
                'crop_mapped_from' => null,
                'warnings' => [],
            ], 200),
            'https://api.open-meteo.com/*' => Http::response([
                'hourly' => [
                    'time' => ['2026-11-21T00:00', '2026-11-21T01:00', '2026-11-21T02:00'],
                    'precipitation' => [0.0, 0.2, 0.1],
                    'precipitation_probability' => [10, 20, 15],
                ],
            ], 200),
        ]);
    }

    public function test_esp32_readings_endpoint_ingests_npk_and_generates_fertilizer_recommendation(): void
    {
        // Use a live (non-demo) device to verify 'sensor' npk_source, as well as demo device for 'demo'
        $liveDevice = IotDevice::create([
            'device_uid' => 'ESP32-LIVE-01',
            'name' => 'Live Field Node',
            'status' => 'online',
            'is_demo' => false,
            'api_token_hash' => hash('sha256', 'live-esp32-token'),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer live-esp32-token',
            'Accept' => 'application/json',
        ])->postJson('/api/v1/readings', [
            'sample_id' => $this->sampleCode,
            'device_id' => $liveDevice->device_uid,
            'ph' => 6.4,
            'moisture_percent' => 52.0,
            'temperature_c' => 26.5,
            'ec_ms_cm' => 1.35,
            'n' => 42.0,
            'p' => 48.0,
            'k' => 65.0,
            'recorded_at' => Carbon::now()->toIso8601String(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.measurements.nitrogen.value', 42)
            ->assertJsonPath('data.measurements.nitrogen.source', 'sensor')
            ->assertJsonPath('data.fertilizer_recommendation.predicted_fertilizer', 'Urea')
            ->assertJsonPath('data.fertilizer_recommendation.model_version', 'gb-v1.0')
            ->assertJsonPath('data.fertilizer_recommendation.fallback_used', false)
            ->assertJsonPath('data.fertilizer_recommendation.npk.nitrogen', 42)
            ->assertJsonPath('data.fertilizer_recommendation.npk.phosphorus', 48)
            ->assertJsonPath('data.fertilizer_recommendation.npk.potassium', 65)
            ->assertJsonPath('data.fertilizer_recommendation.npk.npk_source', 'sensor')
            ->assertJsonPath('data.fertilizer_recommendation.rain_advisory.status', 'proceed');

        $readingId = $response->json('data.reading_id');
        $this->assertDatabaseHas('sensor_readings', [
            'id' => $readingId,
            'nitrogen' => 42.0,
            'phosphorus' => 48.0,
            'potassium' => 65.0,
            'npk_source' => 'sensor',
        ]);

        $this->assertDatabaseHas('fertilizer_recommendations', [
            'reading_id' => $readingId,
            'predicted_fertilizer' => 'Urea',
            'model_version' => 'gb-v1.0',
        ]);
    }

    public function test_esp32_readings_endpoint_is_backward_compatible_without_npk(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validToken,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/readings', [
            'sample_id' => $this->sampleCode,
            'device_id' => $this->deviceUid,
            'ph' => 6.3,
            'moisture_percent' => 50.0,
            'temperature_c' => 25.5,
            'ec_ms_cm' => 1.2,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.fertilizer_recommendation', null);

        $readingId = $response->json('data.reading_id');
        $reading = SensorReading::findOrFail($readingId);
        $this->assertNull($reading->nitrogen);
        $this->assertNull($reading->phosphorus);
        $this->assertNull($reading->potassium);
        $this->assertNull($reading->npk_source);
    }

    public function test_esp32_readings_endpoint_rejects_out_of_range_npk_with_422(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validToken,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/readings', [
            'sample_id' => $this->sampleCode,
            'device_id' => $this->deviceUid,
            'ph' => 6.4,
            'n' => -5.0,
            'p' => 550.0,
            'k' => 'invalid',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['n', 'p', 'k']);
    }

    public function test_web_manual_npk_submission_valid_and_invalid_ranges(): void
    {
        // Valid submission on SS-2025-016 (complete 4-channel calibrated reading)
        $validResponse = $this->post('/report/SS-2025-016/npk', [
            'nitrogen' => 40.0,
            'phosphorus' => 52.0,
            'potassium' => 68.0,
            'crop' => 'Tomato',
            'soil_type' => 'Clay',
            'latitude' => 14.2000,
            'longitude' => 121.2500,
        ]);

        $validResponse->assertRedirect(route('report', ['id' => 'SS-2025-016']));
        $validResponse->assertSessionHas('success');

        $sample = SoilSample::where('sample_code', 'SS-2025-016')->firstOrFail();
        $this->assertSame('Clay', $sample->soil_type);
        $this->assertEquals(14.2000, $sample->latitude);
        $this->assertEquals(121.2500, $sample->longitude);

        $reading = $sample->latestReading()->firstOrFail();
        $this->assertEquals(40.0, $reading->nitrogen);
        $this->assertEquals(52.0, $reading->phosphorus);
        $this->assertEquals(68.0, $reading->potassium);
        $this->assertSame('manual', $reading->npk_source);

        $this->assertDatabaseHas('fertilizer_recommendations', [
            'reading_id' => $reading->id,
            'crop' => 'Tomato',
            'predicted_fertilizer' => 'Urea',
        ]);

        // Invalid range submission (> 500 or < 0)
        $invalidResponse = $this->from('/report/SS-2025-016')->post('/report/SS-2025-016/npk', [
            'nitrogen' => 600.0,
            'phosphorus' => -10.0,
            'potassium' => '',
            'soil_type' => 'InvalidType',
        ]);

        $invalidResponse->assertRedirect('/report/SS-2025-016');
        $invalidResponse->assertSessionHasErrors(['nitrogen', 'phosphorus', 'potassium', 'soil_type']);
    }

    public function test_mobile_api_fertilizer_endpoints_enforce_token_auth_and_return_resource(): void
    {
        // 1. Missing token -> 401
        $getNoToken = $this->getJson('/api/v1/mobile/samples/SS-2025-016/fertilizer');
        $getNoToken->assertStatus(401)
            ->assertJsonPath('code', 'AUTH_TOKEN_MISSING');

        $postNoToken = $this->postJson('/api/v1/mobile/samples/SS-2025-016/fertilizer', [
            'nitrogen' => 45.0,
            'phosphorus' => 50.0,
            'potassium' => 60.0,
        ]);
        $postNoToken->assertStatus(401)
            ->assertJsonPath('code', 'AUTH_TOKEN_MISSING');

        // 2. Invalid token -> 401
        $getBadToken = $this->withHeaders([
            'Authorization' => 'Bearer bad-token-value',
        ])->getJson('/api/v1/mobile/samples/SS-2025-016/fertilizer');
        $getBadToken->assertStatus(401)
            ->assertJsonPath('code', 'AUTH_TOKEN_INVALID');

        // 3. Valid token POST -> 201 + FertilizerRecommendationResource structure
        $postValid = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validToken,
        ])->postJson('/api/v1/mobile/samples/SS-2025-016/fertilizer', [
            'n' => 44.0,
            'p' => 55.0,
            'k' => 70.0,
            'crop' => 'Tomato',
            'soil_type' => 'Loamy',
        ]);

        $postValid->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'reading_id',
                    'crop',
                    'predicted_fertilizer',
                    'confidence',
                    'top_predictions',
                    'dose_kg_ha',
                    'split_schedule',
                    'rain_advisory',
                    'model_version',
                    'fallback_used',
                    'npk' => [
                        'nitrogen',
                        'phosphorus',
                        'potassium',
                        'npk_source',
                    ],
                    'created_at',
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.crop', 'Tomato')
            ->assertJsonPath('data.predicted_fertilizer', 'Urea')
            ->assertJsonPath('data.npk.nitrogen', 44)
            ->assertJsonPath('data.npk.npk_source', 'manual');

        // 4. Valid token GET -> 200 + FertilizerRecommendationResource structure
        $getValid = $this->withHeaders([
            'X-Device-Token' => $this->validToken,
        ])->getJson('/api/v1/mobile/samples/SS-2025-016/fertilizer');

        $getValid->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.predicted_fertilizer', 'Urea')
            ->assertJsonPath('data.npk.nitrogen', 44);
    }

    public function test_csv_export_includes_npk_and_fertilizer_recommendation_columns(): void
    {
        $sample = SoilSample::where('sample_code', 'SS-2025-016')->firstOrFail();
        $reading = $sample->latestReading()->firstOrFail();

        FertilizerRecommendation::create([
            'reading_id' => $reading->id,
            'crop' => 'Tomato',
            'predicted_fertilizer' => 'DAP',
            'confidence' => 0.88,
            'top_predictions' => [['fertilizer' => 'DAP', 'probability' => 0.88]],
            'dose_kg_ha' => 115.5,
            'split_schedule' => [['application_number' => 1, 'day_offset' => 0, 'stage' => 'Basal / At Planting', 'dose_kg_ha' => 115.5]],
            'rain_advisory' => ['status' => 'proceed', 'message' => 'Favorable outlook'],
            'model_version' => 'gb-v1.0',
            'fallback_used' => false,
        ]);

        $response = $this->get('/settings/export/samples');
        $response->assertStatus(200);

        $content = $response->streamedContent();

        $this->assertStringContainsString('Nitrogen (mg/kg)', $content);
        $this->assertStringContainsString('Phosphorus (mg/kg)', $content);
        $this->assertStringContainsString('Potassium (mg/kg)', $content);
        $this->assertStringContainsString('NPK Source', $content);
        $this->assertStringContainsString('Recommended Fertilizer', $content);
        $this->assertStringContainsString('Fertilizer Dose (kg/ha)', $content);
        $this->assertStringContainsString('Rain Advisory', $content);
        $this->assertStringContainsString('DAP', $content);
        $this->assertStringContainsString('115.5', $content);
        $this->assertStringContainsString('proceed', $content);
    }

    public function test_report_view_renders_fertilizer_card_split_schedule_rain_advisory_and_disclaimer_when_npk_present(): void
    {
        $sample = SoilSample::where('sample_code', 'SS-2025-016')->firstOrFail();
        $reading = $sample->latestReading()->firstOrFail();

        FertilizerRecommendation::create([
            'reading_id' => $reading->id,
            'crop' => 'Tomato',
            'predicted_fertilizer' => 'Urea',
            'confidence' => 0.89,
            'top_predictions' => [
                ['fertilizer' => 'Urea', 'probability' => 0.89],
                ['fertilizer' => 'NPK', 'probability' => 0.08],
                ['fertilizer' => 'DAP', 'probability' => 0.03],
            ],
            'dose_kg_ha' => 180.0,
            'split_schedule' => [
                ['application_number' => 1, 'day_offset' => 0, 'stage' => 'Basal / At Planting', 'dose_kg_ha' => 90.0],
                ['application_number' => 2, 'day_offset' => 21, 'stage' => 'Vegetative Top-dress', 'dose_kg_ha' => 90.0],
            ],
            'rain_advisory' => [
                'status' => 'proceed',
                'api_failed' => false,
                'precipitation_sum_mm' => 1.4,
                'max_precipitation_probability' => 20,
                'location_label' => 'Greenhouse 2',
                'message' => '72-hour rainfall outlook is favorable (1.4 mm total, 20% max probability). Safe to proceed with scheduled application.',
            ],
            'model_version' => 'gb-v1.0',
            'fallback_used' => false,
        ]);

        $response = $this->get('/report/SS-2025-016');

        $response->assertStatus(200)
            ->assertSee('Fertilizer Recommendation')
            ->assertSee('Tomato')
            ->assertSee('Urea')
            ->assertSee('180.0 kg/ha')
            ->assertSee('89% confidence')
            ->assertSee('Model: gb-v1.0')
            ->assertSee('82.0 mg/kg')
            ->assertSee('54.0 mg/kg')
            ->assertSee('76.0 mg/kg')
            ->assertSee('NPK source: demo')
            ->assertSee('Basal / At Planting')
            ->assertSee('Vegetative Top-dress')
            ->assertSee('Day +21')
            ->assertSee('90.0 kg/ha')
            ->assertSee('Proceed with Application')
            ->assertSee('Update Manual N-P-K', false)
            ->assertSee(route('report.npk.store', ['id' => 'SS-2025-016']), false)
            ->assertSee('Agronomic Advisory Disclaimer')
            ->assertSee('synthetic reference dataset')
            ->assertSee('local agronomist');
    }

    public function test_report_view_renders_delay_and_manual_check_rain_advisories_and_fallback_badge(): void
    {
        $sample = SoilSample::where('sample_code', 'SS-2025-016')->firstOrFail();
        $reading = $sample->latestReading()->firstOrFail();

        $rec = FertilizerRecommendation::create([
            'reading_id' => $reading->id,
            'crop' => 'Tomato',
            'predicted_fertilizer' => 'DAP',
            'confidence' => 0.75,
            'top_predictions' => [
                ['fertilizer' => 'DAP', 'probability' => 0.75],
                ['fertilizer' => 'NPK', 'probability' => 0.15],
                ['fertilizer' => 'Urea', 'probability' => 0.10],
            ],
            'dose_kg_ha' => 110.0,
            'split_schedule' => [
                ['application_number' => 1, 'day_offset' => 0, 'stage' => 'Basal / At Planting', 'dose_kg_ha' => 110.0],
            ],
            'rain_advisory' => [
                'status' => 'delay',
                'api_failed' => false,
                'precipitation_sum_mm' => 24.5,
                'max_precipitation_probability' => 85,
                'suggested_dry_window' => ['label' => '2026-11-22T06:00 to 2026-11-22T14:00 (8h dry window)'],
                'location_label' => 'Greenhouse 2',
                'message' => 'Heavy rain risk detected over the next 72h. Delay fertilizer application.',
            ],
            'model_version' => 'rule-fallback-v1',
            'fallback_used' => true,
        ]);

        $delayResponse = $this->get('/report/SS-2025-016');
        $delayResponse->assertStatus(200)
            ->assertSee('Rule-based fallback')
            ->assertSee('Delay Application')
            ->assertSee('24.5 mm')
            ->assertSee('85%')
            ->assertSee('2026-11-22T06:00 to 2026-11-22T14:00 (8h dry window)')
            ->assertSee('Single application');

        $rec->update([
            'rain_advisory' => [
                'status' => 'manual_check',
                'api_failed' => true,
                'location_label' => 'Laguna, PH (Default)',
                'message' => 'Unable to reach Open-Meteo weather service. Perform a manual local weather check.',
            ],
        ]);

        $manualResponse = $this->get('/report/SS-2025-016');
        $manualResponse->assertStatus(200)
            ->assertSee('Check Weather Manually')
            ->assertSee('Unable to reach Open-Meteo weather service.');
    }

    public function test_report_view_renders_missing_npk_prompt_and_manual_entry_form_when_npk_is_null(): void
    {
        // Seeded SS-2025-017 has null N-P-K values
        $response = $this->get('/report/SS-2025-017');

        $response->assertStatus(200)
            ->assertSee('N-P-K macronutrient values required')
            ->assertSee('Awaiting N-P-K telemetry')
            ->assertSee(route('report.npk.store', ['id' => 'SS-2025-017']), false)
            ->assertSee('name="nitrogen"', false)
            ->assertSee('name="phosphorus"', false)
            ->assertSee('name="potassium"', false)
            ->assertSee('name="soil_type"', false)
            ->assertSee('name="crop"', false)
            ->assertSee('Agronomic Advisory Disclaimer');
    }

    public function test_analysis_view_includes_optional_npk_and_soil_type_inputs(): void
    {
        $response = $this->get('/analysis');

        $response->assertStatus(200)
            ->assertSee('Optional Macronutrients (N-P-K)')
            ->assertSee('name="nitrogen"', false)
            ->assertSee('name="phosphorus"', false)
            ->assertSee('name="potassium"', false)
            ->assertSee('name="soil_type"', false)
            ->assertSee('Loamy')
            ->assertSee('Sandy')
            ->assertSee('Silt')
            ->assertSee('Clay');
    }

    public function test_history_view_renders_npk_trend_chart_and_npk_summary_column(): void
    {
        $response = $this->get('/history');

        $response->assertStatus(200)
            ->assertSee('Soil N-P-K Trend Lines (mg/kg)')
            ->assertSee('id="npkTrendChart"', false)
            ->assertSee('N-P-K (mg/kg)')
            ->assertSee('N 45 · P 32 · K 58')
            ->assertSee('Not recorded');
    }
}
