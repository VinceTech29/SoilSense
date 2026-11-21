<?php

namespace App\Services;

use App\Models\CropProfile;
use App\Models\FertilizerRecommendation;
use App\Models\SensorReading;
use App\Models\SoilAssessment;
use App\Models\SoilSample;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class FertilizerRecommendationService
{
    private const TIER_PRIORITY = [
        CropSuitabilityService::TIER_STRONG_MATCH => 1,
        CropSuitabilityService::TIER_POTENTIAL_MATCH => 2,
        CropSuitabilityService::TIER_LIMITED_MATCH => 3,
        CropSuitabilityService::TIER_INSUFFICIENT_DATA => 4,
    ];

    public function __construct(
        protected CropSuitabilityService $cropSuitabilityService,
        protected FertilizerPredictionService $predictionService,
        protected FertilizerDosageCalculator $dosageCalculator,
        protected RainGuardService $rainGuardService
    ) {}

    /**
     * Generate and persist a fertilizer recommendation for the top suitable crop (or explicit crop override).
     */
    public function recommend(
        SoilSample $sample,
        ?SensorReading $reading = null,
        ?SoilAssessment $assessment = null,
        ?string $crop = null
    ): FertilizerRecommendation {
        return $this->recommendTopCrops($sample, $reading, $assessment, 1, $crop)->first();
    }

    /**
     * Generate and persist fertilizer recommendations for up to $topN ranked crops (or a single specified crop).
     *
     * @return Collection<int, FertilizerRecommendation>
     */
    public function recommendTopCrops(
        SoilSample $sample,
        ?SensorReading $reading = null,
        ?SoilAssessment $assessment = null,
        int $topN = 1,
        ?string $crop = null
    ): Collection {
        $reading ??= $sample->latestReading;
        if ($reading === null) {
            throw new InvalidArgumentException('A sensor reading is required to generate a fertilizer recommendation.');
        }

        if ($reading->nitrogen === null || $reading->phosphorus === null || $reading->potassium === null) {
            throw new InvalidArgumentException(
                'Soil N-P-K values (nitrogen, phosphorus, potassium) are required to generate a fertilizer recommendation.'
            );
        }

        $targetCrops = $this->resolveTargetCrops($sample, $reading, $assessment, max(1, $topN), $crop);
        $rainAdvisory = $this->rainGuardService->assess($sample);

        return DB::transaction(function () use ($sample, $reading, $targetCrops, $rainAdvisory): Collection {
            $recommendations = collect();

            foreach ($targetCrops as $targetCrop) {
                try {
                    $prediction = $this->predictionService->predict($reading, $targetCrop, $sample);
                } catch (InvalidArgumentException $e) {
                    if ((float) $reading->nitrogen < 0.0 || (float) $reading->phosphorus < 0.0 || (float) $reading->potassium < 0.0) {
                        throw $e;
                    }
                    $prediction = $this->predictionService->predictWithRuleFallback(
                        $reading,
                        $targetCrop,
                        $e->getMessage()
                    );
                }

                $dosage = $this->dosageCalculator->calculate(
                    $prediction['predicted_fertilizer'],
                    $targetCrop,
                    $reading
                );

                $recommendations->push(FertilizerRecommendation::create([
                    'reading_id' => $reading->id,
                    'crop' => $targetCrop,
                    'predicted_fertilizer' => $prediction['predicted_fertilizer'],
                    'confidence' => $prediction['confidence'],
                    'top_predictions' => $prediction['top_predictions'],
                    'dose_kg_ha' => $dosage['dose_kg_ha'],
                    'split_schedule' => $dosage['split_schedule'],
                    'rain_advisory' => $rainAdvisory,
                    'model_version' => $prediction['model_version'],
                    'fallback_used' => $prediction['fallback_used'],
                ]));
            }

            return $recommendations;
        });
    }

    /**
     * Determine target crops from explicit override, existing SoilAssessment evaluations, or CropSuitabilityService.
     *
     * @return list<string>
     */
    protected function resolveTargetCrops(
        SoilSample $sample,
        SensorReading $reading,
        ?SoilAssessment $assessment,
        int $topN,
        ?string $cropOverride
    ): array {
        if ($cropOverride !== null && trim($cropOverride) !== '') {
            return [trim($cropOverride)];
        }

        $assessment ??= $sample->latestAssessment;

        if ($assessment !== null) {
            $evaluations = $assessment->relationLoaded('cropEvaluations')
                ? $assessment->cropEvaluations
                : $assessment->cropEvaluations()->with('crop')->get();

            if ($evaluations->isNotEmpty()) {
                $ranked = $evaluations
                    ->filter(fn ($eval) => $eval->crop !== null && trim((string) $eval->crop->name) !== '')
                    ->sort(function ($a, $b) {
                        $rankA = self::TIER_PRIORITY[$a->suitability_tier] ?? 99;
                        $rankB = self::TIER_PRIORITY[$b->suitability_tier] ?? 99;
                        if ($rankA !== $rankB) {
                            return $rankA <=> $rankB;
                        }
                        return ($b->match_score ?? -1.0) <=> ($a->match_score ?? -1.0);
                    })
                    ->values();

                $names = $ranked
                    ->take($topN)
                    ->map(fn ($eval) => (string) $eval->crop->name)
                    ->unique()
                    ->values()
                    ->all();

                if ($names !== []) {
                    return $names;
                }
            }
        }

        $crops = CropProfile::with('requirements')->get();
        if ($crops->isEmpty()) {
            throw new RuntimeException('No crop profiles are available to determine a target crop.');
        }

        $evaluated = $this->cropSuitabilityService->evaluateAllCrops($crops, $reading);
        $names = collect($evaluated)
            ->pluck('crop_name')
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->unique()
            ->take($topN)
            ->values()
            ->all();

        if ($names === []) {
            throw new RuntimeException('Unable to determine a target crop from available crop evaluations.');
        }

        return $names;
    }
}
