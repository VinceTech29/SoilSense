<?php

namespace Tests\Unit;

use App\Models\SoilSample;
use App\Services\RainGuardService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RainGuardServiceTest extends TestCase
{
    protected RainGuardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Config::set('fertilizer.rain_guard', [
            'open_meteo_url' => 'https://api.open-meteo.test/v1/forecast',
            'timeout_seconds' => 5,
            'cache_ttl_seconds' => 3600,
            'rain_threshold_mm' => 10.0,
            'rain_probability_threshold' => 60,
            'default_latitude' => 14.1699,
            'default_longitude' => 121.2441,
            'default_location_label' => 'Laguna, PH (Default)',
        ]);

        $this->service = new RainGuardService();
    }

    private function buildHourlyPayload(array $precipValues, array $probValues): array
    {
        $times = [];
        $base = strtotime('2026-11-22T00:00:00');
        foreach ($precipValues as $idx => $_) {
            $times[] = date('Y-m-d\TH:00', $base + ($idx * 3600));
        }

        return [
            'hourly' => [
                'time' => $times,
                'precipitation' => $precipValues,
                'precipitation_probability' => $probValues,
            ],
        ];
    }

    public function test_returns_proceed_and_caches_successful_forecast_by_rounded_coordinates(): void
    {
        $precip = array_fill(0, 72, 0.1); // sum = 7.2 mm <= 10.0 mm
        $probs = array_fill(0, 72, 30);   // max = 30% <= 60%

        Http::fake([
            'https://api.open-meteo.test/*' => Http::response($this->buildHourlyPayload($precip, $probs), 200),
        ]);

        $sample1 = new SoilSample([
            'location' => 'Plot A',
            'latitude' => 14.1234,
            'longitude' => 121.5612,
        ]);

        $first = $this->service->assess($sample1);

        Http::assertSent(function (Request $request) {
            $data = $request->data();
            return str_starts_with($request->url(), 'https://api.open-meteo.test/v1/forecast')
                && (float) $data['latitude'] === 14.1234
                && (float) $data['longitude'] === 121.5612
                && $data['hourly'] === 'precipitation,precipitation_probability'
                && (int) $data['forecast_days'] === 3
                && $data['timezone'] === 'auto';
        });

        $this->assertSame(RainGuardService::STATUS_PROCEED, $first['status']);
        $this->assertFalse($first['api_failed']);
        $this->assertFalse($first['cached']);
        $this->assertSame('sample', $first['coordinate_source']);
        $this->assertEqualsWithDelta(7.2, $first['precipitation_sum_mm'], 0.01);
        $this->assertSame(30, $first['max_precipitation_probability']);

        // Second call rounding to the same 2-decimal bucket (14.12, 121.56) hits cache
        $sample2 = new SoilSample([
            'location' => 'Plot A2',
            'latitude' => 14.1249,
            'longitude' => 121.5649,
        ]);
        $second = $this->service->assess($sample2);

        $this->assertTrue($second['cached']);
        $this->assertSame(RainGuardService::STATUS_PROCEED, $second['status']);
        Http::assertSentCount(1);
    }

    public function test_uses_default_coordinates_when_sample_coordinates_are_missing(): void
    {
        $precip = array_fill(0, 24, 0.0);
        $probs = array_fill(0, 24, 15);

        Http::fake([
            'https://api.open-meteo.test/*' => Http::response($this->buildHourlyPayload($precip, $probs), 200),
        ]);

        $sample = new SoilSample([
            'location' => 'Demo Plot',
            'latitude' => null,
            'longitude' => null,
        ]);

        $result = $this->service->assess($sample);

        $this->assertSame('default', $result['coordinate_source']);
        $this->assertEqualsWithDelta(14.1699, $result['latitude'], 0.0001);
        $this->assertEqualsWithDelta(121.2441, $result['longitude'], 0.0001);
        $this->assertSame('Laguna, PH (Default)', $result['location_label']);
        $this->assertSame(RainGuardService::STATUS_PROCEED, $result['status']);
    }

    public function test_returns_delay_with_suggested_dry_window_when_rainfall_exceeds_thresholds(): void
    {
        // First 12 hours: heavy rain (1.5 mm/h = 18.0 mm, 85% prob)
        // Next 24 hours: dry window (0.0 mm/h, 15% prob)
        // Remaining 36 hours: light intermittent rain (0.1 mm/h, 40% prob)
        $precip = array_merge(array_fill(0, 12, 1.5), array_fill(0, 24, 0.0), array_fill(0, 36, 0.1));
        $probs = array_merge(array_fill(0, 12, 85), array_fill(0, 24, 15), array_fill(0, 36, 40));

        Http::fake([
            'https://api.open-meteo.test/*' => Http::response($this->buildHourlyPayload($precip, $probs), 200),
        ]);

        $result = $this->service->assess(new SoilSample(['latitude' => 14.5, 'longitude' => 121.0]));

        $this->assertSame(RainGuardService::STATUS_DELAY, $result['status']);
        $this->assertFalse($result['api_failed']);
        $this->assertGreaterThan(10.0, $result['precipitation_sum_mm']);
        $this->assertSame(85, $result['max_precipitation_probability']);
        $this->assertIsArray($result['suggested_dry_window']);
        $this->assertGreaterThanOrEqual(24, $result['suggested_dry_window']['hours']);
        $this->assertStringContainsString('dry window', $result['suggested_dry_window']['label']);
    }

    public function test_returns_delay_when_only_probability_exceeds_threshold(): void
    {
        // Total precip 4.0 mm <= 10.0 mm, but max prob 75% > 60%
        $precip = array_merge([2.0, 2.0], array_fill(0, 22, 0.0));
        $probs = array_merge([75, 70], array_fill(0, 22, 20));

        Http::fake([
            'https://api.open-meteo.test/*' => Http::response($this->buildHourlyPayload($precip, $probs), 200),
        ]);

        $result = $this->service->assess();

        $this->assertSame(RainGuardService::STATUS_DELAY, $result['status']);
        $this->assertSame(75, $result['max_precipitation_probability']);
        $this->assertNotNull($result['suggested_dry_window']);
    }

    public function test_fails_safe_to_manual_check_and_does_not_cache_failures_or_malformed_payloads(): void
    {
        Http::fakeSequence('https://api.open-meteo.test/*')
            ->push(['error' => 'upstream_unavailable'], 503)
            ->push(['unexpected' => 'payload'], 200)
            ->push($this->buildHourlyPayload(array_fill(0, 24, 0.0), array_fill(0, 24, 10)), 200);

        $sample = new SoilSample(['latitude' => 14.20, 'longitude' => 121.20]);

        // 1. HTTP 503 failure -> manual_check, NOT cached
        $first = $this->service->assess($sample);
        $this->assertSame(RainGuardService::STATUS_MANUAL_CHECK, $first['status']);
        $this->assertTrue($first['api_failed']);
        $this->assertFalse($first['cached']);
        $this->assertStringContainsString('manual', strtolower($first['message']));

        // 2. Malformed JSON -> manual_check, NOT cached
        $second = $this->service->assess($sample);
        $this->assertSame(RainGuardService::STATUS_MANUAL_CHECK, $second['status']);
        $this->assertTrue($second['api_failed']);
        $this->assertFalse($second['cached']);

        // 3. Recovery -> proceeds and caches
        $third = $this->service->assess($sample);
        $this->assertSame(RainGuardService::STATUS_PROCEED, $third['status']);
        $this->assertFalse($third['api_failed']);
        $this->assertFalse($third['cached']);

        Http::assertSentCount(3);

        // 4. Network ConnectionException on fresh coords -> manual_check, NOT cached
        Http::fake([
            'https://api.open-meteo.test/*' => function () {
                throw new ConnectionException('Network unreachable');
            },
        ]);

        $offline = $this->service->assess(new SoilSample(['latitude' => 15.0, 'longitude' => 120.0]));
        $this->assertSame(RainGuardService::STATUS_MANUAL_CHECK, $offline['status']);
        $this->assertTrue($offline['api_failed']);
        $this->assertStringContainsString('manual', strtolower($offline['message']));
    }
}
