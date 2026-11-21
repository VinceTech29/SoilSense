<?php

namespace App\Services;

use App\Models\SensorReading;
use App\Models\SoilSample;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class FertilizerPredictionService
{
    public const FALLBACK_MODEL_VERSION = 'rule-fallback-v1';

    private const SOIL_TYPE_MAP = [
        'clay' => 'Clay',
        'loam' => 'Loamy',
        'loamy' => 'Loamy',
        'sand' => 'Sandy',
        'sandy' => 'Sandy',
        'silt' => 'Silt',
        'silty' => 'Silt',
    ];

    private const FALLBACK_CANDIDATES = [
        'NPK',
        'Compost',
        'Zinc Sulphate',
        'Urea',
        'DAP',
        'MOP',
    ];

    public function __construct(
        protected ?CropSuitabilityService $cropSuitabilityService = null
    ) {
        $this->cropSuitabilityService ??= new CropSuitabilityService();
    }

    /**
     * Predict recommended fertilizer class using SoilSense's 4 sensors (pH, EC, moisture, temperature)
     * plus crop and soil type via the FastAPI ML service, falling back deterministically to
     * 4-sensor agronomic rules on incomplete/uncalibrated telemetry or transport/auth/confidence issues.
     *
     * @throws InvalidArgumentException When crop is empty or sensor values are outside physical bounds.
     */
    public function predict(
        SensorReading $reading,
        string $crop,
        ?SoilSample $sample = null,
        ?string $soilType = null
    ): array {
        $cleanedCrop = trim($crop);
        if ($cleanedCrop === '') {
            throw new InvalidArgumentException('Target crop name is required for fertilizer prediction.');
        }

        $this->validatePhysicalBounds($reading);

        [$mappedCrop, $cropMappedFrom] = $this->mapCropForModel($cleanedCrop);
        $resolvedSoilType = $this->normalizeSoilType(
            $soilType ?? $sample?->soil_type ?? $reading->soilSample?->soil_type
        );

        // Missing 4-sensor telemetry -> deterministic 4-sensor rule fallback (no HTTP call)
        if ($reading->ph === null || $reading->ec === null || $reading->moisture === null || $reading->temperature === null) {
            return $this->buildFallbackPrediction(
                $reading,
                $cropMappedFrom,
                'Incomplete 4-sensor telemetry (pH, EC, calibrated moisture, and temperature are required for ML inference); using deterministic 4-sensor rule fallback.'
            );
        }

        // Uncalibrated moisture -> deterministic 4-sensor rule fallback (no HTTP call)
        if (!$this->cropSuitabilityService->isMoistureCalibrated($reading)) {
            $unit = $reading->moisture_unit ?: 'unspecified';
            return $this->buildFallbackPrediction(
                $reading,
                $cropMappedFrom,
                "Uncalibrated soil moisture ({$unit}) cannot be sent for ML fertilizer prediction; using deterministic 4-sensor rule fallback."
            );
        }

        $moisture = (float) $reading->moisture;
        if ($moisture < 0.0 || $moisture > 100.0) {
            throw new InvalidArgumentException('Calibrated soil moisture must be within physical bounds (0–100%).');
        }

        $mlConfig = config('fertilizer.ml_service', []);
        $apiKey = trim((string) ($mlConfig['api_key'] ?? ''));

        if ($apiKey === '') {
            return $this->buildFallbackPrediction(
                $reading,
                $cropMappedFrom,
                'ML service API key is not configured.'
            );
        }

        $baseUrl = rtrim((string) ($mlConfig['url'] ?? 'http://127.0.0.1:8001'), '/');
        $timeoutSeconds = max(1, (int) ($mlConfig['timeout_seconds'] ?? 5));
        $retryTimes = max(0, (int) ($mlConfig['retry_times'] ?? 1));
        $threshold = (float) ($mlConfig['low_confidence_threshold'] ?? 0.55);

        $payload = [
            'ph' => (float) $reading->ph,
            'ec' => (float) $reading->ec,
            'moisture' => $moisture,
            'temperature' => (float) $reading->temperature,
            'crop' => $mappedCrop,
            'soil_type' => $resolvedSoilType,
        ];

        try {
            $request = Http::acceptJson()
                ->withHeaders(['X-API-KEY' => $apiKey])
                ->timeout($timeoutSeconds);

            if ($retryTimes > 0) {
                $request = $request->retry(1 + $retryTimes, 10, throw: false);
            }

            $response = $request->post("{$baseUrl}/predict", $payload);
        } catch (\Throwable $e) {
            return $this->buildFallbackPrediction(
                $reading,
                $cropMappedFrom,
                'ML service request failed: ' . $e->getMessage()
            );
        }

        if (!$response->successful()) {
            return $this->buildFallbackPrediction(
                $reading,
                $cropMappedFrom,
                sprintf('ML service returned HTTP %d.', $response->status())
            );
        }

        $data = $response->json();
        if (
            !is_array($data)
            || empty($data['predicted_fertilizer'])
            || !isset($data['confidence'])
            || !is_numeric($data['confidence'])
            || !isset($data['top_predictions'])
            || !is_array($data['top_predictions'])
            || count($data['top_predictions']) === 0
        ) {
            return $this->buildFallbackPrediction(
                $reading,
                $cropMappedFrom,
                'ML service returned a malformed prediction payload.'
            );
        }

        $confidence = (float) $data['confidence'];
        $isLowConfidence = (bool) ($data['low_confidence'] ?? false) || $confidence < $threshold;

        if ($isLowConfidence) {
            return $this->buildFallbackPrediction(
                $reading,
                $cropMappedFrom,
                sprintf(
                    'ML prediction confidence (%.2f) is below threshold (%.2f).',
                    $confidence,
                    $threshold
                )
            );
        }

        $topPredictions = [];
        foreach ($data['top_predictions'] as $entry) {
            if (!is_array($entry) || empty($entry['fertilizer']) || !isset($entry['probability'])) {
                continue;
            }
            $topPredictions[] = [
                'fertilizer' => (string) $entry['fertilizer'],
                'probability' => round((float) $entry['probability'], 6),
            ];
        }

        if ($topPredictions === []) {
            return $this->buildFallbackPrediction(
                $reading,
                $cropMappedFrom,
                'ML service returned empty top predictions.'
            );
        }

        return [
            'predicted_fertilizer' => (string) $data['predicted_fertilizer'],
            'confidence' => round($confidence, 6),
            'low_confidence' => false,
            'top_predictions' => $topPredictions,
            'model_version' => (string) ($data['model_version'] ?? 'unknown'),
            'crop_mapped_from' => $data['crop_mapped_from'] ?? $cropMappedFrom,
            'warnings' => array_values(array_map('strval', (array) ($data['warnings'] ?? []))),
            'fallback_used' => false,
            'fallback_reason' => null,
        ];
    }

    /**
     * Validate physical bounds for any present 4-sensor measurements.
     */
    protected function validatePhysicalBounds(SensorReading $reading): void
    {
        if ($reading->ph !== null) {
            $ph = (float) $reading->ph;
            if ($ph < 0.0 || $ph > 14.0) {
                throw new InvalidArgumentException('Soil pH must be within physical bounds (0–14).');
            }
        }

        if ($reading->ec !== null) {
            $ec = (float) $reading->ec;
            if ($ec < 0.0 || $ec > 30.0) {
                throw new InvalidArgumentException('Electrical conductivity must be within physical bounds (0–30 mS/cm).');
            }
        }

        if ($reading->moisture !== null) {
            $moisture = (float) $reading->moisture;
            if ($moisture < 0.0) {
                throw new InvalidArgumentException('Soil moisture cannot be negative.');
            }
        }

        if ($reading->temperature !== null) {
            $temperature = (float) $reading->temperature;
            if ($temperature < -10.0 || $temperature > 60.0) {
                throw new InvalidArgumentException('Soil temperature must be within physical bounds (-10°C to 60°C).');
            }
        }
    }

    /**
     * Map SoilSense crop names to the ML model's training vocabulary using config aliases.
     *
     * @return array{0: string, 1: string|null}
     */
    protected function mapCropForModel(string $crop): array
    {
        $aliases = config('fertilizer.crop_model_aliases', []);
        if (isset($aliases[$crop])) {
            $mapped = (string) $aliases[$crop];
            return [$mapped, $mapped !== $crop ? $crop : null];
        }

        $lower = strtolower($crop);
        foreach ($aliases as $key => $target) {
            if (strtolower((string) $key) === $lower) {
                $mapped = (string) $target;
                return [$mapped, $mapped !== $crop ? $crop : null];
            }
        }

        return [$crop, null];
    }

    /**
     * Normalize sample soil type into one of the four model-supported categories.
     */
    protected function normalizeSoilType(?string $soilType): string
    {
        if ($soilType === null) {
            return 'Loamy';
        }

        $cleaned = strtolower(trim($soilType));
        if ($cleaned === '') {
            return 'Loamy';
        }

        return self::SOIL_TYPE_MAP[$cleaned] ?? 'Loamy';
    }

    /**
     * Compute a deterministic 4-sensor agronomic rule-based fallback prediction with ranked top_predictions.
     */
    public function predictWithRuleFallback(SensorReading $reading, string $crop, string $reason): array
    {
        [, $cropMappedFrom] = $this->mapCropForModel(trim($crop));

        return $this->buildFallbackPrediction($reading, $cropMappedFrom, $reason);
    }

    protected function buildFallbackPrediction(
        SensorReading $reading,
        ?string $cropMappedFrom,
        string $reason
    ): array {
        $rules = config('fertilizer.fallback_rules', []);
        $phAcidic = (float) ($rules['ph_acidic'] ?? 5.5);
        $phAlkaline = (float) ($rules['ph_alkaline'] ?? 7.8);
        $ecHigh = (float) ($rules['ec_high'] ?? 2.5);

        $ph = $reading->ph !== null ? (float) $reading->ph : null;
        $ec = $reading->ec !== null ? (float) $reading->ec : null;

        $matchedConditions = [];
        if ($ph !== null && $ph < $phAcidic) {
            $matchedConditions[] = 'Compost';
        }
        if ($ph !== null && $ph > $phAlkaline) {
            $matchedConditions[] = 'Zinc Sulphate';
        }
        if ($ec !== null && $ec > $ecHigh) {
            $matchedConditions[] = 'Compost';
        }

        $matchedConditions = array_values(array_unique($matchedConditions));
        $primary = $matchedConditions[0] ?? 'NPK';
        $primaryProbability = $matchedConditions !== [] ? 0.75 : 0.70;

        $orderedCandidates = array_values(array_unique(array_merge(
            [$primary],
            $matchedConditions,
            ['NPK'],
            self::FALLBACK_CANDIDATES
        )));

        $secondaryProb = $matchedConditions !== [] ? 0.15 : 0.20;
        $tertiaryProb = round(1.0 - $primaryProbability - $secondaryProb, 6);

        $topPredictions = [
            ['fertilizer' => $orderedCandidates[0], 'probability' => $primaryProbability],
            ['fertilizer' => $orderedCandidates[1], 'probability' => $secondaryProb],
            ['fertilizer' => $orderedCandidates[2], 'probability' => $tertiaryProb],
        ];

        return [
            'predicted_fertilizer' => $primary,
            'confidence' => $primaryProbability,
            'low_confidence' => false,
            'top_predictions' => $topPredictions,
            'model_version' => self::FALLBACK_MODEL_VERSION,
            'crop_mapped_from' => $cropMappedFrom,
            'warnings' => [$reason],
            'fallback_used' => true,
            'fallback_reason' => $reason,
        ];
    }
}
