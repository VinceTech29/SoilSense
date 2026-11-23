<?php

namespace App\Services;

use App\Models\SoilSample;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RainGuardService
{
    public const STATUS_PROCEED = 'proceed';
    public const STATUS_DELAY = 'delay';
    public const STATUS_MANUAL_CHECK = 'manual_check';

    private const MAX_FORECAST_HOURS = 72;
    private const DRY_PRECIP_THRESHOLD_MM = 0.2;

    /**
     * Assess 72-hour rainfall risk via Open-Meteo and return a Rain Guard advisory.
     * Caches successful parsed advisories only; fails safe to manual_check on errors.
     */
    public function assess(?SoilSample $sample = null, ?float $latitude = null, ?float $longitude = null): array
    {
        $config = (array) config('fertilizer.rain_guard', []);
        $rainThresholdMm = (float) ($config['rain_threshold_mm'] ?? 10.0);
        $probThreshold = (int) ($config['rain_probability_threshold'] ?? 60);
        $ttlSeconds = max(0, (int) ($config['cache_ttl_seconds'] ?? 3600));
        $timeoutSeconds = max(1, (int) ($config['timeout_seconds'] ?? 5));
        $openMeteoUrl = (string) ($config['open_meteo_url'] ?? 'https://api.open-meteo.com/v1/forecast');

        [$resolvedLat, $resolvedLon, $coordSource, $locationLabel] = $this->resolveCoordinates(
            $sample,
            $latitude,
            $longitude,
            $config
        );

        $cacheKey = $this->buildCacheKey($resolvedLat, $resolvedLon);

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['status'])) {
            $cached['cached'] = true;
            return $cached;
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(min(2, $timeoutSeconds))
                ->timeout($timeoutSeconds)
                ->get($openMeteoUrl, [
                    'latitude' => $resolvedLat,
                    'longitude' => $resolvedLon,
                    'hourly' => 'precipitation,precipitation_probability',
                    'forecast_days' => 3,
                    'timezone' => 'auto',
                ]);
        } catch (\Throwable $e) {
            return $this->buildManualCheckAdvisory(
                $resolvedLat,
                $resolvedLon,
                $coordSource,
                $locationLabel,
                $rainThresholdMm,
                $probThreshold,
                'Unable to reach Open-Meteo weather service. Perform a manual local weather check before applying fertilizer.'
            );
        }

        if (!$response->successful()) {
            return $this->buildManualCheckAdvisory(
                $resolvedLat,
                $resolvedLon,
                $coordSource,
                $locationLabel,
                $rainThresholdMm,
                $probThreshold,
                sprintf(
                    'Weather service returned HTTP %d. Perform a manual local weather check before applying fertilizer.',
                    $response->status()
                )
            );
        }

        $payload = $response->json();
        $parsed = $this->parseHourlySeries($payload);

        if ($parsed === null) {
            return $this->buildManualCheckAdvisory(
                $resolvedLat,
                $resolvedLon,
                $coordSource,
                $locationLabel,
                $rainThresholdMm,
                $probThreshold,
                'Weather forecast response was malformed or incomplete. Perform a manual local weather check before applying fertilizer.'
            );
        }

        $precipSum = round(array_sum($parsed['precipitation']), 2);
        $maxProb = (int) round(max($parsed['probability']));
        $shouldDelay = $precipSum > $rainThresholdMm || $maxProb > $probThreshold;

        $dryWindow = $shouldDelay
            ? $this->findDryWindow($parsed['time'], $parsed['precipitation'], $parsed['probability'], $probThreshold)
            : null;

        if ($shouldDelay) {
            $status = self::STATUS_DELAY;
            $windowNote = $dryWindow !== null
                ? " Suggested dry window: {$dryWindow['label']}."
                : ' No clear 6-hour dry window was found within the next 72 hours.';
            $message = sprintf(
                'Heavy rain risk detected over the next 72h (%.1f mm total, %d%% max probability). Delay fertilizer application to reduce nutrient runoff.%s',
                $precipSum,
                $maxProb,
                $windowNote
            );
        } else {
            $status = self::STATUS_PROCEED;
            $message = sprintf(
                '72-hour rainfall outlook is favorable (%.1f mm total, %d%% max probability). Safe to proceed with scheduled application.',
                $precipSum,
                $maxProb
            );
        }

        $advisory = [
            'status' => $status,
            'api_failed' => false,
            'cached' => false,
            'coordinate_source' => $coordSource,
            'latitude' => $resolvedLat,
            'longitude' => $resolvedLon,
            'location_label' => $locationLabel,
            'precipitation_sum_mm' => $precipSum,
            'max_precipitation_probability' => $maxProb,
            'rain_threshold_mm' => $rainThresholdMm,
            'rain_probability_threshold' => $probThreshold,
            'suggested_dry_window' => $dryWindow,
            'message' => $message,
            'evaluated_at' => Carbon::now()->toIso8601String(),
        ];

        if ($ttlSeconds > 0) {
            Cache::put($cacheKey, $advisory, $ttlSeconds);
        }

        return $advisory;
    }

    /**
     * @return array{0: float, 1: float, 2: string, 3: string}
     */
    protected function resolveCoordinates(
        ?SoilSample $sample,
        ?float $latitude,
        ?float $longitude,
        array $config
    ): array {
        if ($this->areValidCoordinates($latitude, $longitude)) {
            return [
                (float) $latitude,
                (float) $longitude,
                'sample',
                $sample?->location ?: sprintf('%.4f, %.4f', $latitude, $longitude),
            ];
        }

        if ($sample !== null && $this->areValidCoordinates($sample->latitude, $sample->longitude)) {
            return [
                (float) $sample->latitude,
                (float) $sample->longitude,
                'sample',
                $sample->location ?: sprintf('%.4f, %.4f', $sample->latitude, $sample->longitude),
            ];
        }

        $defaultLat = (float) ($config['default_latitude'] ?? 14.1699);
        $defaultLon = (float) ($config['default_longitude'] ?? 121.2441);
        $defaultLabel = (string) ($config['default_location_label'] ?? 'Laguna, PH (Default)');

        return [$defaultLat, $defaultLon, 'default', $defaultLabel];
    }

    protected function areValidCoordinates(?float $lat, ?float $lon): bool
    {
        return $lat !== null
            && $lon !== null
            && $lat >= -90.0 && $lat <= 90.0
            && $lon >= -180.0 && $lon <= 180.0;
    }

    protected function buildCacheKey(float $latitude, float $longitude): string
    {
        return sprintf(
            'rain_guard:forecast:%s:%s',
            number_format($latitude, 2, '.', ''),
            number_format($longitude, 2, '.', '')
        );
    }

    /**
     * Validate and extract up to 72 hourly forecast entries from Open-Meteo payload.
     *
     * @return array{time: list<string>, precipitation: list<float>, probability: list<float>}|null
     */
    protected function parseHourlySeries(mixed $payload): ?array
    {
        if (!is_array($payload) || !isset($payload['hourly']) || !is_array($payload['hourly'])) {
            return null;
        }

        $hourly = $payload['hourly'];
        $precipRaw = $hourly['precipitation'] ?? null;
        $probRaw = $hourly['precipitation_probability'] ?? null;
        $timeRaw = $hourly['time'] ?? [];

        if (!is_array($precipRaw) || !is_array($probRaw) || $precipRaw === [] || $probRaw === []) {
            return null;
        }

        $limit = min(self::MAX_FORECAST_HOURS, count($precipRaw), count($probRaw));
        $times = [];
        $precips = [];
        $probs = [];

        for ($i = 0; $i < $limit; $i++) {
            if (!is_numeric($precipRaw[$i]) || !is_numeric($probRaw[$i])) {
                continue;
            }
            $times[] = isset($timeRaw[$i]) ? (string) $timeRaw[$i] : sprintf('hour_%02d', $i);
            $precips[] = max(0.0, (float) $precipRaw[$i]);
            $probs[] = max(0.0, min(100.0, (float) $probRaw[$i]));
        }

        if ($precips === [] || $probs === []) {
            return null;
        }

        return [
            'time' => $times,
            'precipitation' => $precips,
            'probability' => $probs,
        ];
    }

    /**
     * Find the best contiguous dry window (>= 6h preferred, falling back to >= 3h) within the forecast.
     *
     * @param list<string> $times
     * @param list<float> $precips
     * @param list<float> $probs
     * @return array{start: string, end: string, hours: int, label: string}|null
     */
    protected function findDryWindow(array $times, array $precips, array $probs, int $probThreshold): ?array
    {
        $count = count($precips);
        $bestRun = null;
        $currentStart = null;
        $currentLen = 0;

        for ($i = 0; $i < $count; $i++) {
            $isDry = $precips[$i] <= self::DRY_PRECIP_THRESHOLD_MM && $probs[$i] <= $probThreshold;
            if ($isDry) {
                if ($currentStart === null) {
                    $currentStart = $i;
                    $currentLen = 1;
                } else {
                    $currentLen++;
                }

                if ($bestRun === null || $currentLen > $bestRun['hours']) {
                    $bestRun = [
                        'start_idx' => $currentStart,
                        'end_idx' => $i,
                        'hours' => $currentLen,
                    ];
                }
            } else {
                $currentStart = null;
                $currentLen = 0;
            }
        }

        if ($bestRun === null || $bestRun['hours'] < 3) {
            return null;
        }

        $start = $times[$bestRun['start_idx']];
        $end = $times[$bestRun['end_idx']];

        return [
            'start' => $start,
            'end' => $end,
            'hours' => $bestRun['hours'],
            'label' => "{$start} to {$end} ({$bestRun['hours']}h dry window)",
        ];
    }

    protected function buildManualCheckAdvisory(
        float $latitude,
        float $longitude,
        string $coordSource,
        string $locationLabel,
        float $rainThresholdMm,
        int $probThreshold,
        string $message
    ): array {
        return [
            'status' => self::STATUS_MANUAL_CHECK,
            'api_failed' => true,
            'cached' => false,
            'coordinate_source' => $coordSource,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_label' => $locationLabel,
            'precipitation_sum_mm' => null,
            'max_precipitation_probability' => null,
            'rain_threshold_mm' => $rainThresholdMm,
            'rain_probability_threshold' => $probThreshold,
            'suggested_dry_window' => null,
            'message' => $message,
            'evaluated_at' => Carbon::now()->toIso8601String(),
        ];
    }
}
