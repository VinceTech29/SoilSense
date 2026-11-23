<?php

namespace App\Services;

use App\Models\SensorReading;
use InvalidArgumentException;

class FertilizerDosageCalculator
{
    private const NUTRIENT_TO_TARGET = [
        'n' => 'n',
        'p2o5' => 'p',
        'k2o' => 'k',
    ];

    /**
     * Calculate deterministic crop-baseline fertilizer application dosage (kg/ha) and split schedule
     * strictly from config('fertilizer') without soil N-P-K subtraction.
     *
     * @throws InvalidArgumentException When the fertilizer grade or its fraction specification is unsupported.
     */
    public function calculate(string $fertilizerClass, string $crop, ?SensorReading $reading = null): array
    {
        [$gradeKey, $grade] = $this->resolveGrade($fertilizerClass);
        $cropTargets = $this->resolveCropTargets($crop);

        $primaryNutrient = (string) ($grade['primary_nutrient'] ?? '');
        $fractions = (array) ($grade['fractions'] ?? []);
        $baseRate = $grade['base_rate_kg_ha'] ?? null;

        $targetKgHa = null;

        if (
            in_array($primaryNutrient, ['amendment', 'micronutrient'], true)
            || ($baseRate !== null && !isset(self::NUTRIENT_TO_TARGET[$primaryNutrient]))
        ) {
            $rawDose = max(0.0, (float) ($baseRate ?? 0.0));
        } else {
            if (!isset(self::NUTRIENT_TO_TARGET[$primaryNutrient])) {
                throw new InvalidArgumentException("Unsupported primary nutrient '{$primaryNutrient}' for grade '{$gradeKey}'.");
            }

            $targetKey = self::NUTRIENT_TO_TARGET[$primaryNutrient];
            $primaryFraction = (float) ($fractions[$primaryNutrient] ?? 0.0);
            if ($primaryFraction <= 0.0) {
                throw new InvalidArgumentException("Invalid primary nutrient fraction for grade '{$gradeKey}'.");
            }

            $targetKgHa = round(max(0.0, (float) ($cropTargets[$targetKey] ?? 0.0)), 2);
            $rawDose = $targetKgHa <= 0.0 ? 0.0 : max(0.0, $targetKgHa / $primaryFraction);
        }

        $doseKgHa = round(max(0.0, $rawDose), 2);
        $maxSingleApp = (float) config('fertilizer.max_single_application_kg_ha', 150.0);
        $splitIntervals = array_values((array) config('fertilizer.split_intervals_days', [0, 21, 42]));

        $splitSchedule = $this->buildSplitSchedule($doseKgHa, $maxSingleApp, $splitIntervals);
        $numApplications = count($splitSchedule);
        $capped = $doseKgHa > $maxSingleApp && $numApplications > 1;

        $delivered = [];
        foreach ($fractions as $nutrientKey => $fraction) {
            $delivered[$nutrientKey] = round($doseKgHa * (float) $fraction, 2);
        }

        return [
            'dose_kg_ha' => $doseKgHa,
            'split_schedule' => $splitSchedule,
            'nutrient_breakdown' => [
                'basis' => 'crop_baseline_without_soil_npk',
                'grade' => $gradeKey,
                'grade_label' => (string) ($grade['label'] ?? $gradeKey),
                'primary_nutrient' => $primaryNutrient,
                'target_kg_ha' => $targetKgHa,
                'delivered_kg_ha' => $delivered,
                'crop_targets_kg_ha' => $cropTargets,
            ],
            'capped' => $capped,
            'num_applications' => $numApplications,
        ];
    }

    /**
     * Split doses above the single-application cap into even parts whose sum equals dose_kg_ha.
     *
     * @param list<int|float> $splitIntervals
     * @return list<array{application_number: int, day_offset: int, stage: string, dose_kg_ha: float}>
     */
    protected function buildSplitSchedule(float $doseKgHa, float $maxSingleApp, array $splitIntervals): array
    {
        if ($doseKgHa <= 0.0) {
            return [];
        }

        $firstOffset = (int) ($splitIntervals[0] ?? 0);

        if ($maxSingleApp <= 0.0 || $doseKgHa <= $maxSingleApp) {
            return [
                [
                    'application_number' => 1,
                    'day_offset' => $firstOffset,
                    'stage' => $this->resolveApplicationStage(0),
                    'dose_kg_ha' => $doseKgHa,
                ],
            ];
        }

        $numApplications = (int) ceil($doseKgHa / $maxSingleApp);
        $intervalStep = count($splitIntervals) >= 2
            ? max(1, (int) $splitIntervals[1] - (int) $splitIntervals[0])
            : 21;

        $totalCents = (int) round($doseKgHa * 100);
        $baseCents = intdiv($totalCents, $numApplications);
        $remainderCents = $totalCents % $numApplications;

        $schedule = [];
        for ($i = 0; $i < $numApplications; $i++) {
            $dayOffset = isset($splitIntervals[$i])
                ? (int) $splitIntervals[$i]
                : (int) ($splitIntervals[count($splitIntervals) - 1] ?? 0) + ($intervalStep * ($i - count($splitIntervals) + 1));

            $appCents = $baseCents + ($i < $remainderCents ? 1 : 0);

            $schedule[] = [
                'application_number' => $i + 1,
                'day_offset' => $dayOffset,
                'stage' => $this->resolveApplicationStage($i),
                'dose_kg_ha' => round($appCents / 100, 2),
            ];
        }

        return $schedule;
    }

    protected function resolveApplicationStage(int $index): string
    {
        return match ($index) {
            0 => 'Basal / At Planting',
            1 => 'Vegetative Top-dress',
            2 => 'Mid-Season Side-dress',
            default => 'Follow-up Application #' . ($index + 1),
        };
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function resolveGrade(string $fertilizerClass): array
    {
        $grades = (array) config('fertilizer.grades', []);
        $cleaned = trim($fertilizerClass);

        if (isset($grades[$cleaned])) {
            return [$cleaned, (array) $grades[$cleaned]];
        }

        $lower = strtolower($cleaned);
        foreach ($grades as $key => $spec) {
            if (strtolower((string) $key) === $lower) {
                return [(string) $key, (array) $spec];
            }
        }

        throw new InvalidArgumentException("Unsupported fertilizer grade '{$fertilizerClass}'.");
    }

    /**
     * @return array<string, float>
     */
    protected function resolveCropTargets(string $crop): array
    {
        $targets = (array) config('fertilizer.crop_targets', []);
        $cleaned = trim($crop);

        if (isset($targets[$cleaned])) {
            return array_map('floatval', (array) $targets[$cleaned]);
        }

        $lower = strtolower($cleaned);
        foreach ($targets as $key => $values) {
            if (strtolower((string) $key) === $lower) {
                return array_map('floatval', (array) $values);
            }
        }

        $default = (array) ($targets['default'] ?? ['n' => 100.0, 'p' => 60.0, 'k' => 80.0]);
        return array_map('floatval', $default);
    }
}
