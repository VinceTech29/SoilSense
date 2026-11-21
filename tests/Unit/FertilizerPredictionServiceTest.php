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
            'nitrogen' => 45.0,
            'phosphorus' => 50.0,
            'potassium' => 60.0,
            'calibration_metadata' => ['calibrated' => true],
        ], $overrides));
    }

    public function test_calls_fastapi_ml_service_with_api_key_and_crop_alias_mapping(): void
    {
        Http::fake([
            'http://ml.soilsense.test/predict' => Http::response([
                'predicted_fertilizer' => 'Urea',
                'confidence' => 0.91,
                'low_confidence' => false,
                'top_predictions' => [
                    ['fertilizer' => 'Urea', 'probability' => 0.91],
                    ['fertilizer' => 'NPK', 'probability' => 0.06],
                    ['fertilizer' => 'DAP', 'probability' => 0.03],
                ],
                'model_version' => 'gb-v1.0',
                'crop_mapped_from' => 'Sweet Corn',
                'warnings' => [],
            ], 200),
        ]);

        $reading = $this->makeValidReading();
        $sample = new SoilSample(['soil_type' => 'clay']);

        $result = $this->service->predict($reading, 'Sweet Corn', $sample);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'http://ml.soilsense.test/predict'
                && $request->hasHeader('X-API-KEY', 'test-ml-api-key')
                && $request->hasHeader('Accept', 'application/json')
                && $request['crop'] === 'Maize'
                && $request['soil_type'] === 'Clay'
                && $request['n'] === 45.0
                && $request['p'] === 50.0
                && $request['k'] === 60.0
                && $request['ph'] === 6.4
                && $request['ec'] === 1.2
                && $request['moisture'] === 45.0
                && $request['temperature'] === 26.5;
        });

        $this->assertFalse($result['fallback_used']);
        $this->assertNull($result['fallback_reason']);
        $this->assertSame('Urea', $result['predicted_fertilizer']);
        $this->assertEquals(0.91, $result['confidence']);
        $this->assertSame('gb-v1.0', $result['model_version']);
        $this->assertSame('Sweet Corn', $result['crop_mapped_from']);
        $this->assertCount(3, $result['top_predictions']);
    }

    public function test_rejects_uncalibrated_moisture_units_before_calling_ml(): void
    {
        Http::fake();

        foreach (['raw index', 'adc', 'raw', ''] as $unit) {
            $reading = $this->makeValidReading(['moisture_unit' => $unit]);

            try {
                $this->service->predict($reading, 'Tomato');
                $this->fail("Expected InvalidArgumentException for moisture_unit='{$unit}'");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Uncalibrated soil moisture', $e->getMessage());
            }
        }

        $uncalibratedMetaReading = $this->makeValidReading([
            'moisture_unit' => '% VWC',
            'calibration_metadata' => ['calibrated' => false],
        ]);

        try {
            $this->service->predict($uncalibratedMetaReading, 'Tomato');
            $this->fail('Expected InvalidArgumentException when calibration_metadata.calibrated is false');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Uncalibrated soil moisture', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_rejects_missing_npk_or_sensor_fields_before_calling_ml(): void
    {
        Http::fake();

        $missingNpk = $this->makeValidReading(['nitrogen' => null]);
        try {
            $this->service->predict($missingNpk, 'Tomato');
            $this->fail('Expected InvalidArgumentException for missing nitrogen');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('N-P-K', $e->getMessage());
        }

        $missingEc = $this->makeValidReading(['ec' => null]);
        try {
            $this->service->predict($missingEc, 'Tomato');
            $this->fail('Expected InvalidArgumentException for missing EC');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('sensor parameters', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_falls_back_without_http_call_when_api_key_is_missing(): void
    {
        Config::set('fertilizer.ml_service.api_key', '');
        Http::fake();

        $reading = $this->makeValidReading(['nitrogen' => 35.0]);
        $result = $this->service->predict($reading, 'Tomato');

        Http::assertNothingSent();
        $this->assertTrue($result['fallback_used']);
        $this->assertSame(FertilizerPredictionService::FALLBACK_MODEL_VERSION, $result['model_version']);
        $this->assertSame('Urea', $result['predicted_fertilizer']);
        $this->assertStringContainsString('API key', (string) $result['fallback_reason']);
        $this->assertCount(3, $result['top_predictions']);
    }

    public function test_falls_back_on_http_error_and_connection_timeout(): void
    {
        Http::fake([
            'http://ml.soilsense.test/predict' => Http::response(['error' => 'server_error'], 500),
        ]);

        $reading = $this->makeValidReading(['nitrogen' => 75.0, 'phosphorus' => 25.0]);
        $result = $this->service->predict($reading, 'Tomato');

        $this->assertTrue($result['fallback_used']);
        $this->assertSame('DAP', $result['predicted_fertilizer']);
        $this->assertSame(FertilizerPredictionService::FALLBACK_MODEL_VERSION, $result['model_version']);
        $this->assertStringContainsString('HTTP 500', (string) $result['fallback_reason']);

        Http::fake([
            'http://ml.soilsense.test/predict' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out');
            },
        ]);

        $timeoutResult = $this->service->predict($reading, 'Tomato');

        $this->assertTrue($timeoutResult['fallback_used']);
        $this->assertSame('DAP', $timeoutResult['predicted_fertilizer']);
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
                'model_version' => 'gb-v1.0',
            ], 200),
        ]);

        $reading = $this->makeValidReading([
            'nitrogen' => 70.0,
            'phosphorus' => 55.0,
            'potassium' => 30.0,
        ]);

        $result = $this->service->predict($reading, 'Tomato');

        $this->assertTrue($result['fallback_used']);
        $this->assertSame('MOP', $result['predicted_fertilizer']);
        $this->assertSame(FertilizerPredictionService::FALLBACK_MODEL_VERSION, $result['model_version']);
        $this->assertStringContainsString('below threshold', (string) $result['fallback_reason']);
    }

    public function test_deterministic_fallback_covers_all_agronomic_rule_branches(): void
    {
        Config::set('fertilizer.ml_service.api_key', '');
        Http::fake();

        $cases = [
            ['overrides' => ['nitrogen' => 59.0, 'phosphorus' => 50.0, 'potassium' => 50.0, 'ph' => 6.5], 'expected' => 'Urea'],
            ['overrides' => ['nitrogen' => 60.0, 'phosphorus' => 39.0, 'potassium' => 50.0, 'ph' => 6.5], 'expected' => 'DAP'],
            ['overrides' => ['nitrogen' => 60.0, 'phosphorus' => 40.0, 'potassium' => 39.0, 'ph' => 6.5], 'expected' => 'MOP'],
            ['overrides' => ['nitrogen' => 60.0, 'phosphorus' => 40.0, 'potassium' => 40.0, 'ph' => 5.4], 'expected' => 'Compost'],
            ['overrides' => ['nitrogen' => 60.0, 'phosphorus' => 40.0, 'potassium' => 40.0, 'ph' => 7.9], 'expected' => 'Zinc Sulphate'],
            ['overrides' => ['nitrogen' => 60.0, 'phosphorus' => 40.0, 'potassium' => 40.0, 'ph' => 6.5], 'expected' => 'NPK'],
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
