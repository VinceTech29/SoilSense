<?php

namespace Tests\Unit;

use App\Models\SensorReading;
use App\Models\SoilSample;
use App\Services\FertilizerPredictionService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class FertilizerPredictionServiceTest extends TestCase
{
    protected FertilizerPredictionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('fertilizer.ml_service', [
            'url' => 'http://ml.soilsense.test',
            'api_key' => 'test-ml-api-key',
            'timeout_seconds' => 5,
            'retry_times' => 1,
            'low_confidence_threshold' => 0.55,
        ]);

        $this->service = new FertilizerPredictionService();
    }

    private function makeValidReading(array $overrides = []): SensorReading
    {
        return new SensorReading(array_merge([
            'ph' => 6.4,
            'ec' => 1.2,
            'moisture' => 45.0,
            'moisture_unit' => '% VWC',
            'temperature' => 26.5,
            'calibration_metadata' => ['calibrated' => true],
        ], $overrides));
    }

    public function test_calls_fastapi_ml_service_with_4_sensor_payload_api_key_and_crop_alias_mapping(): void
    {
        Http::fake([
            'http://ml.soilsense.test/predict' => Http::response([
                'predicted_fertilizer' => 'Compost',
                'confidence' => 0.91,
                'low_confidence' => false,
                'top_predictions' => [
                    ['fertilizer' => 'Compost', 'probability' => 0.91],
                    ['fertilizer' => 'NPK', 'probability' => 0.06],
                    ['fertilizer' => 'Zinc Sulphate', 'probability' => 0.03],
                ],
                'model_version' => 'rf-4sensor-v1.0',
                'crop_mapped_from' => 'Sweet Corn',
                'warnings' => [],
            ], 200),
        ]);

        $reading = $this->makeValidReading(['ph' => 5.1]);
        $sample = new SoilSample(['soil_type' => 'clay']);

        $result = $this->service->predict($reading, 'Sweet Corn', $sample);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'http://ml.soilsense.test/predict'
                && $request->hasHeader('X-API-KEY', 'test-ml-api-key')
                && $request->hasHeader('Accept', 'application/json')
                && !array_key_exists('n', $body)
                && !array_key_exists('p', $body)
                && !array_key_exists('k', $body)
                && $body['crop'] === 'Maize'
                && $body['soil_type'] === 'Clay'
                && $body['ph'] === 5.1
                && $body['ec'] === 1.2
                && $body['moisture'] === 45.0
                && $body['temperature'] === 26.5;
        });

        $this->assertFalse($result['fallback_used']);
        $this->assertNull($result['fallback_reason']);
        $this->assertSame('Compost', $result['predicted_fertilizer']);
        $this->assertEquals(0.91, $result['confidence']);
        $this->assertSame('rf-4sensor-v1.0', $result['model_version']);
        $this->assertSame('Sweet Corn', $result['crop_mapped_from']);
        $this->assertCount(3, $result['top_predictions']);
    }

    public function test_uncalibrated_moisture_uses_rule_fallback_without_calling_ml(): void
    {
        Http::fake();

        foreach (['raw index', 'adc', 'raw', ''] as $unit) {
            $reading = $this->makeValidReading(['moisture_unit' => $unit, 'ph' => 5.1]);

            $result = $this->service->predict($reading, 'Tomato');

            $this->assertTrue($result['fallback_used']);
            $this->assertSame(FertilizerPredictionService::FALLBACK_MODEL_VERSION, $result['model_version']);
            $this->assertSame('Compost', $result['predicted_fertilizer']);
            $this->assertStringContainsString('Uncalibrated soil moisture', (string) $result['fallback_reason']);
        }

        $uncalibratedMetaReading = $this->makeValidReading([
            'moisture_unit' => '% VWC',
            'calibration_metadata' => ['calibrated' => false],
            'ph' => 8.1,
        ]);

        $metaResult = $this->service->predict($uncalibratedMetaReading, 'Tomato');
        $this->assertTrue($metaResult['fallback_used']);
        $this->assertSame('Zinc Sulphate', $metaResult['predicted_fertilizer']);
        $this->assertStringContainsString('Uncalibrated soil moisture', (string) $metaResult['fallback_reason']);

        Http::assertNothingSent();
    }

    public function test_missing_4_sensor_fields_use_rule_fallback_without_calling_ml(): void
    {
        Http::fake();

        $missingEc = $this->makeValidReading(['ec' => null, 'ph' => 6.4]);
        $result = $this->service->predict($missingEc, 'Tomato');

        $this->assertTrue($result['fallback_used']);
        $this->assertSame(FertilizerPredictionService::FALLBACK_MODEL_VERSION, $result['model_version']);
        $this->assertSame('NPK', $result['predicted_fertilizer']);
        $this->assertStringContainsString('Incomplete 4-sensor telemetry', (string) $result['fallback_reason']);

        Http::assertNothingSent();
    }

    public function test_rejects_out_of_bounds_sensor_values_before_calling_ml(): void
    {
        Http::fake();

        $invalidPh = $this->makeValidReading(['ph' => 15.2]);
        try {
            $this->service->predict($invalidPh, 'Tomato');
            $this->fail('Expected InvalidArgumentException for out-of-bounds pH');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Soil pH', $e->getMessage());
        }

        $invalidEc = $this->makeValidReading(['ec' => -1.0]);
        try {
            $this->service->predict($invalidEc, 'Tomato');
            $this->fail('Expected InvalidArgumentException for negative EC');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Electrical conductivity', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_falls_back_without_http_call_when_api_key_is_missing(): void
    {
        Config::set('fertilizer.ml_service.api_key', '');
        Http::fake();

        $reading = $this->makeValidReading(['ph' => 5.2]);
        $result = $this->service->predict($reading, 'Tomato');

        Http::assertNothingSent();
        $this->assertTrue($result['fallback_used']);
        $this->assertSame(FertilizerPredictionService::FALLBACK_MODEL_VERSION, $result['model_version']);
        $this->assertSame('Compost', $result['predicted_fertilizer']);
        $this->assertStringContainsString('API key', (string) $result['fallback_reason']);
        $this->assertCount(3, $result['top_predictions']);
    }

    public function test_falls_back_on_http_error_and_connection_timeout(): void
    {
        Http::fake([
            'http://ml.soilsense.test/predict' => Http::response(['error' => 'server_error'], 500),
        ]);

        $reading = $this->makeValidReading(['ph' => 8.1, 'ec' => 1.0]);
        $result = $this->service->predict($reading, 'Tomato');

        $this->assertTrue($result['fallback_used']);
        $this->assertSame('Zinc Sulphate', $result['predicted_fertilizer']);
        $this->assertSame(FertilizerPredictionService::FALLBACK_MODEL_VERSION, $result['model_version']);
        $this->assertStringContainsString('HTTP 500', (string) $result['fallback_reason']);

        Http::fake([
            'http://ml.soilsense.test/predict' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out');
            },
        ]);

        $timeoutResult = $this->service->predict($reading, 'Tomato');

        $this->assertTrue($timeoutResult['fallback_used']);
        $this->assertSame('Zinc Sulphate', $timeoutResult['predicted_fertilizer']);
        $this->assertStringContainsString('timed out', (string) $timeoutResult['fallback_reason']);
    }

    public function test_falls_back_when_ml_confidence_is_below_threshold_or_flagged_low(): void
    {
        Http::fake([
            'http://ml.soilsense.test/predict' => Http::response([
                'predicted_fertilizer' => 'SSP',
                'confidence' => 0.42,
                'low_confidence' => true,
                'top_predictions' => [
                    ['fertilizer' => 'SSP', 'probability' => 0.42],
                    ['fertilizer' => 'NPK', 'probability' => 0.35],
                    ['fertilizer' => 'DAP', 'probability' => 0.23],
                ],
                'model_version' => 'rf-4sensor-v1.0',
            ], 200),
        ]);

        $reading = $this->makeValidReading([
            'ph' => 6.6,
            'ec' => 3.1,
        ]);

        $result = $this->service->predict($reading, 'Tomato');

        $this->assertTrue($result['fallback_used']);
        $this->assertSame('Compost', $result['predicted_fertilizer']);
        $this->assertSame(FertilizerPredictionService::FALLBACK_MODEL_VERSION, $result['model_version']);
        $this->assertStringContainsString('below threshold', (string) $result['fallback_reason']);
    }

    public function test_deterministic_fallback_covers_all_4_sensor_agronomic_rule_branches(): void
    {
        Config::set('fertilizer.ml_service.api_key', '');
        Http::fake();

        $cases = [
            ['overrides' => ['ph' => 5.4, 'ec' => 1.0], 'expected' => 'Compost'],
            ['overrides' => ['ph' => 7.9, 'ec' => 1.0], 'expected' => 'Zinc Sulphate'],
            ['overrides' => ['ph' => 6.5, 'ec' => 2.6], 'expected' => 'Compost'],
            ['overrides' => ['ph' => 6.5, 'ec' => 1.2], 'expected' => 'NPK'],
        ];

        foreach ($cases as $case) {
            $result = $this->service->predict($this->makeValidReading($case['overrides']), 'Tomato');

            $this->assertTrue($result['fallback_used']);
            $this->assertSame($case['expected'], $result['predicted_fertilizer']);
            $this->assertSame($case['expected'], $result['top_predictions'][0]['fertilizer']);
            $this->assertCount(3, $result['top_predictions']);

            $probSum = array_sum(array_column($result['top_predictions'], 'probability'));
            $this->assertEqualsWithDelta(1.0, $probSum, 0.0001);
        }
    }
}
