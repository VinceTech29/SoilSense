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
        'Urea',
        'DAP',
        'MOP',
        'Compost',
        'Zinc Sulphate',
        'NPK',
    ];

    public function __construct(
        protected ?CropSuitabilityService $cropSuitabilityService = null
    ) {
        $this->cropSuitabilityService ??= new CropSuitabilityService();
    }

    /**
     * Predict recommended fertilizer class using the FastAPI ML service,
     * falling back deterministically to agronomic rules on transport/auth/confidence issues.
     *
     * @throws InvalidArgumentException When required NPK/sensor fields are missing, out of physical bounds, or moisture is uncalibrated.
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

        $this->validateReadingForPrediction($reading);

        [$mappedCrop, $cropMappedFrom] = $this->mapCropForModel($cleanedCrop);
        $resolvedSoilType = $this->normalizeSoilType(
            $soilType ?? $sample?->soil_type ?? $reading->soilSample?->soil_type
        );

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
            'n' => (float) $reading->nitrogen,
            'p' => (float) $reading->phosphorus,
            'k' => (float) $reading->potassium,
            'ph' => (float) $reading->ph,
            'ec' => (float) $reading->ec,
            'moisture' => (float) $reading->moisture,
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
     * Validate that all required NPK and calibrated sensor channels exist and are physically plausible.
     */
    protected function validateReadingForPrediction(SensorReading $reading): void
    {
        if ($reading->nitrogen === null || $reading->phosphorus === null || $reading->potassium === null) {
            throw new InvalidArgumentException(
                'Soil N-P-K values (nitrogen, phosphorus, potassium) are required before fertilizer prediction.'
            );
        }

        if ($reading->ph === null || $reading->ec === null || $reading->moisture === null || $reading->temperature === null) {
            throw new InvalidArgumentException(
                'All soil sensor parameters (pH, EC, calibrated moisture, temperature) are required before fertilizer prediction.'
            );
        }

        if (!$this->cropSuitabilityService->isMoistureCalibrated($reading)) {
            $unit = $reading->moisture_unit ?: 'unspecified';
            throw new InvalidArgumentException(
                "Uncalibrated soil moisture ({$unit}) cannot be sent for ML fertilizer prediction."
            );
        }

        $n = (float) $reading->nitrogen;
        $p = (float) $reading->phosphorus;
        $k = (float) $reading->potassium;
        $ph = (float) $reading->ph;
        $ec = (float) $reading->ec;
        $moisture = (float) $reading->moisture;
        $temperature = (float) $reading->temperature;

        if ($n < 0.0 || $p < 0.0 || $k < 0.0) {
            throw new InvalidArgumentException('Soil N-P-K values cannot be negative.');
        }

        if ($ph < 0.0 || $ph > 14.0) {
            throw new InvalidArgumentException('Soil pH must be within physical bounds (0–14).');
        }

        if ($ec < 0.0 || $ec > 30.0) {
            throw new InvalidArgumentException('Electrical conductivity must be within physical bounds (0–30 mS/cm).');
        }

        if ($moisture < 0.0 || $moisture > 100.0) {
            throw new InvalidArgumentException('Calibrated soil moisture must be within physical bounds (0–100%).');
        }

        if ($temperature < -10.0 || $temperature > 60.0) {
            throw new InvalidArgumentException('Soil temperature must be within physical bounds (-10°C to 60°C).');
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
     * Compute a deterministic agronomic rule-based fallback prediction with ranked top_predictions.
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
        $nLow = (float) ($rules['nitrogen_low'] ?? 60.0);
        $pLow = (float) ($rules['phosphorus_low'] ?? 40.0);
        $kLow = (float) ($rules['potassium_low'] ?? 40.0);
        $phAcidic = (float) ($rules['ph_acidic'] ?? 5.5);
        $phAlkaline = (float) ($rules['ph_alkaline'] ?? 7.8);

        $n = (float) $reading->nitrogen;
        $p = (float) $reading->phosphorus;
        $k = (float) $reading->potassium;
        $ph = $reading->ph !== null ? (float) $reading->ph : 6.5;

        $matchedConditions = [];
        if ($n < $nLow) {
            $matchedConditions[] = 'Urea';
        }
        if ($p < $pLow) {
            $matchedConditions[] = 'DAP';
        }
        if ($k < $kLow) {
            $matchedConditions[] = 'MOP';
        }
        if ($ph < $phAcidic) {
            $matchedConditions[] = 'Compost';
        }
        if ($ph > $phAlkaline) {
            $matchedConditions[] = 'Zinc Sulphate';
        }

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
