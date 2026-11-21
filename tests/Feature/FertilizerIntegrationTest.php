<?php

namespace Tests\Feature;

use App\Models\FertilizerRecommendation;
use App\Models\IotDevice;
use App\Models\SoilSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
                'predicted_fertilizer' => 'Compost',
                'confidence' => 0.89,
                'low_confidence' => false,
                'top_predictions' => [
                    ['fertilizer' => 'Compost', 'probability' => 0.89],
                    ['fertilizer' => 'NPK', 'probability' => 0.08],
                    ['fertilizer' => 'Zinc Sulphate', 'probability' => 0.03],
                ],
                'model_version' => 'rf-4sensor-v1.0',
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

    public function test_esp32_readings_endpoint_generates_fertilizer_recommendation_from_4_sensors(): void
    {
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
            'ph' => 5.2,
            'moisture_percent' => 52.0,
            'temperature_c' => 26.5,
            'ec_ms_cm' => 1.35,
            'recorded_at' => Carbon::now()->toIso8601String(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.assessment.triggered', true)
            ->assertJsonPath('data.fertilizer_recommendation.predicted_fertilizer', 'Compost')
            ->assertJsonPath('data.fertilizer_recommendation.model_version', 'rf-4sensor-v1.0')
            ->assertJsonPath('data.fertilizer_recommendation.fallback_used', false)
            ->assertJsonPath('data.fertilizer_recommendation.rain_advisory.status', 'proceed');

        $measurements = $response->json('data.measurements');
        $this->assertArrayNotHasKey('nitrogen', $measurements);
        $this->assertArrayNotHasKey('phosphorus', $measurements);
        $this->assertArrayNotHasKey('potassium', $measurements);

        $fertRec = $response->json('data.fertilizer_recommendation');
        $this->assertArrayNotHasKey('npk', $fertRec);

        $readingId = $response->json('data.reading_id');
        $this->assertDatabaseHas('fertilizer_recommendations', [
            'reading_id' => $readingId,
            'predicted_fertilizer' => 'Compost',
            'model_version' => 'rf-4sensor-v1.0',
        ]);

        Http::assertSent(function (Request $request) {
            if ($request->url() !== 'http://ml.soilsense.test/predict') {
                return false;
            }
            $body = $request->data();
            return !array_key_exists('n', $body)
                && !array_key_exists('p', $body)
                && !array_key_exists('k', $body)
                && $body['ph'] === 5.2
                && $body['ec'] === 1.35;
        });
    }

    public function test_esp32_readings_endpoint_skips_fertilizer_when_assessment_not_triggered(): void
    {
        SoilSample::create([
            'sample_code' => 'SS-SPARSE-001',
            'name' => 'Sparse Probe Sample',
            'collected_at' => Carbon::now(),
            'testing_method' => 'prototype_sensor',
            'source_type' => 'demo',
            'assessment_status' => 'incomplete',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validToken,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/readings', [
            'sample_id' => 'SS-SPARSE-001',
            'device_id' => $this->deviceUid,
            'ph' => 6.3,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.assessment.triggered', false)
            ->assertJsonPath('data.fertilizer_recommendation', null);
    }

    public function test_web_fertilizer_refresh_endpoint_recalculates_without_npk_and_validates_inputs(): void
    {
        $validResponse = $this->post('/report/SS-2025-016/fertilizer', [
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
        $this->assertDatabaseHas('fertilizer_recommendations', [
            'reading_id' => $reading->id,
            'crop' => 'Tomato',
            'predicted_fertilizer' => 'Compost',
        ]);

        // Invalid soil_type and coordinates
        $invalidResponse = $this->from('/report/SS-2025-016')->post('/report/SS-2025-016/fertilizer', [
            'soil_type' => 'InvalidType',
            'latitude' => 120.0,
            'longitude' => -200.0,
        ]);

        $invalidResponse->assertRedirect('/report/SS-2025-016');
        $invalidResponse->assertSessionHasErrors(['soil_type', 'latitude', 'longitude']);
    }

    public function test_mobile_api_fertilizer_endpoints_enforce_token_auth_and_omit_npk(): void
    {
        // 1. Missing token -> 401
        $getNoToken = $this->getJson('/api/v1/mobile/samples/SS-2025-016/fertilizer');
        $getNoToken->assertStatus(401)
            ->assertJsonPath('code', 'AUTH_TOKEN_MISSING');

        $postNoToken = $this->postJson('/api/v1/mobile/samples/SS-2025-016/fertilizer', [
            'crop' => 'Tomato',
            'soil_type' => 'Loamy',
        ]);
        $postNoToken->assertStatus(401)
            ->assertJsonPath('code', 'AUTH_TOKEN_MISSING');

        // 2. Invalid token -> 401
        $getBadToken = $this->withHeaders([
            'Authorization' => 'Bearer bad-token-value',
        ])->getJson('/api/v1/mobile/samples/SS-2025-016/fertilizer');
        $getBadToken->assertStatus(401)
            ->assertJsonPath('code', 'AUTH_TOKEN_INVALID');

        // 3. Valid token POST -> 201 + FertilizerRecommendationResource without npk
        $postValid = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validToken,
        ])->postJson('/api/v1/mobile/samples/SS-2025-016/fertilizer', [
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
                    'created_at',
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.crop', 'Tomato')
            ->assertJsonPath('data.predicted_fertilizer', 'Compost');

        $this->assertArrayNotHasKey('npk', $postValid->json('data'));

        // 4. Valid token GET -> 200
        $getValid = $this->withHeaders([
            'X-Device-Token' => $this->validToken,
        ])->getJson('/api/v1/mobile/samples/SS-2025-016/fertilizer');

        $getValid->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.predicted_fertilizer', 'Compost');

        $this->assertArrayNotHasKey('npk', $getValid->json('data'));
    }

    public function test_csv_export_omits_npk_columns_and_keeps_fertilizer_recommendation_columns(): void
    {
        $sample = SoilSample::where('sample_code', 'SS-2025-016')->firstOrFail();
        $reading = $sample->latestReading()->firstOrFail();

        FertilizerRecommendation::create([
            'reading_id' => $reading->id,
            'crop' => 'Tomato',
            'predicted_fertilizer' => 'NPK',
            'confidence' => 0.88,
            'top_predictions' => [['fertilizer' => 'NPK', 'probability' => 0.88]],
            'dose_kg_ha' => 857.14,
            'split_schedule' => [['application_number' => 1, 'day_offset' => 0, 'stage' => 'Basal / At Planting', 'dose_kg_ha' => 142.86]],
            'rain_advisory' => ['status' => 'proceed', 'message' => 'Favorable outlook'],
            'model_version' => 'rf-4sensor-v1.0',
            'fallback_used' => false,
        ]);

        $response = $this->get('/settings/export/samples');
        $response->assertStatus(200);

        $content = $response->streamedContent();

        $this->assertStringNotContainsString('Nitrogen (mg/kg)', $content);
        $this->assertStringNotContainsString('Phosphorus (mg/kg)', $content);
        $this->assertStringNotContainsString('Potassium (mg/kg)', $content);
        $this->assertStringNotContainsString('NPK Source', $content);

        $this->assertStringContainsString('Recommended Fertilizer', $content);
        $this->assertStringContainsString('Fertilizer Dose (kg/ha)', $content);
        $this->assertStringContainsString('Rain Advisory', $content);
        $this->assertStringContainsString('NPK', $content);
        $this->assertStringContainsString('857.14', $content);
        $this->assertStringContainsString('proceed', $content);
    }

    public function test_report_view_renders_fertilizer_card_recalculation_form_and_4_sensor_disclaimer_without_npk_inputs(): void
    {
        $sample = SoilSample::where('sample_code', 'SS-2025-016')->firstOrFail();
        $reading = $sample->latestReading()->firstOrFail();

        FertilizerRecommendation::create([
            'reading_id' => $reading->id,
            'crop' => 'Tomato',
            'predicted_fertilizer' => 'Compost',
            'confidence' => 0.89,
            'top_predictions' => [
                ['fertilizer' => 'Compost', 'probability' => 0.89],
                ['fertilizer' => 'NPK', 'probability' => 0.08],
                ['fertilizer' => 'Zinc Sulphate', 'probability' => 0.03],
            ],
            'dose_kg_ha' => 1000.0,
            'split_schedule' => [
                ['application_number' => 1, 'day_offset' => 0, 'stage' => 'Basal / At Planting', 'dose_kg_ha' => 142.86],
                ['application_number' => 2, 'day_offset' => 21, 'stage' => 'Vegetative Top-dress', 'dose_kg_ha' => 142.86],
            ],
            'rain_advisory' => [
                'status' => 'proceed',
                'api_failed' => false,
                'precipitation_sum_mm' => 1.4,
                'max_precipitation_probability' => 20,
                'location_label' => 'Greenhouse 2',
                'message' => '72-hour rainfall outlook is favorable (1.4 mm total, 20% max probability). Safe to proceed with scheduled application.',
            ],
            'model_version' => 'rf-4sensor-v1.0',
            'fallback_used' => false,
        ]);

        $response = $this->get('/report/SS-2025-016');

        $response->assertStatus(200)
            ->assertSee('Fertilizer Recommendation')
            ->assertSee('Tomato')
            ->assertSee('Compost')
            ->assertSee('1,000.0 kg/ha')
            ->assertSee('89% confidence')
            ->assertSee('Model: rf-4sensor-v1.0')
            ->assertSee('Basal / At Planting')
            ->assertSee('Vegetative Top-dress')
            ->assertSee('Day +21')
            ->assertSee('Proceed with Application')
            ->assertSee(route('report.fertilizer.refresh', ['id' => 'SS-2025-016']), false)
            ->assertSee('Agronomic Advisory Disclaimer')
            ->assertSee('4-sensor telemetry')
            ->assertSee('without laboratory N-P-K soil testing')
            ->assertDontSee('name="nitrogen"', false)
            ->assertDontSee('name="phosphorus"', false)
            ->assertDontSee('name="potassium"', false)
            ->assertDontSee('NPK source:')
            ->assertDontSee('N-P-K macronutrient values required');
    }

    public function test_report_view_auto_generates_fallback_recommendation_for_partial_4_sensor_sample(): void
    {
        // Seeded SS-2025-017 has pH=6.50, temp=27.0, moisture=null, ec=null
        $response = $this->get('/report/SS-2025-017');

        $response->assertStatus(200)
            ->assertSee('Fertilizer Recommendation')
            ->assertSee('Rule-based fallback')
            ->assertSee('Model: rule-fallback-v1')
            ->assertSee('NPK')
            ->assertSee(route('report.fertilizer.refresh', ['id' => 'SS-2025-017']), false)
            ->assertDontSee('N-P-K macronutrient values required')
            ->assertDontSee('Awaiting N-P-K telemetry')
            ->assertDontSee('name="nitrogen"', false);
    }

    public function test_report_view_renders_delay_and_manual_check_rain_advisories_and_fallback_badge(): void
    {
        $sample = SoilSample::where('sample_code', 'SS-2025-016')->firstOrFail();
        $reading = $sample->latestReading()->firstOrFail();

        $rec = FertilizerRecommendation::create([
            'reading_id' => $reading->id,
            'crop' => 'Tomato',
            'predicted_fertilizer' => 'Zinc Sulphate',
            'confidence' => 0.75,
            'top_predictions' => [
                ['fertilizer' => 'Zinc Sulphate', 'probability' => 0.75],
                ['fertilizer' => 'NPK', 'probability' => 0.15],
                ['fertilizer' => 'Compost', 'probability' => 0.10],
            ],
            'dose_kg_ha' => 20.0,
            'split_schedule' => [
                ['application_number' => 1, 'day_offset' => 0, 'stage' => 'Basal / At Planting', 'dose_kg_ha' => 20.0],
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

    public function test_analysis_view_keeps_soil_type_and_omits_npk_inputs(): void
    {
        $response = $this->get('/analysis');

        $response->assertStatus(200)
            ->assertSee('Optional Soil Type')
            ->assertSee('name="soil_type"', false)
            ->assertSee('Loamy')
            ->assertSee('Sandy')
            ->assertSee('Silt')
            ->assertSee('Clay')
            ->assertDontSee('name="nitrogen"', false)
            ->assertDontSee('name="phosphorus"', false)
            ->assertDontSee('name="potassium"', false)
            ->assertDontSee('Optional Macronutrients (N-P-K)');
    }

    public function test_history_view_renders_4_sensor_trend_chart_and_omits_npk_column(): void
    {
        $response = $this->get('/history');

        $response->assertStatus(200)
            ->assertSee('Soil Sensor Trend Lines')
            ->assertSee('id="sensorTrendChart"', false)
            ->assertDontSee('id="npkTrendChart"', false)
            ->assertDontSee('N-P-K (mg/kg)');
    }
}
