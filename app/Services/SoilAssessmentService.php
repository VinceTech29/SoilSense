<?php

namespace App\Services;

use App\Models\AssessmentCropEvaluation;
use App\Models\CropProfile;
use App\Models\SensorReading;
use App\Models\SoilAssessment;
use App\Models\SoilSample;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SoilAssessmentService
{
    public function __construct(
        protected CropSuitabilityService $cropSuitabilityService
    ) {}

    /**
     * Audit sensor reading validity, bounds, and calibration status.
     *
     * @param SensorReading|null $reading
     * @return array
     */
    public function auditReading(?SensorReading $reading): array
    {
        $usableCount = 0;
        $missingInfo = [];
        $qualityNotes = [];

        if (!$reading) {
            return [
                'usable_count' => 0,
                'total_count' => 4,
                'is_ph_valid' => false,
                'is_temp_valid' => false,
                'is_moisture_calibrated' => false,
                'is_ec_valid' => false,
                'is_stale' => false,
                'missing_parameters' => [
                    'Soil pH sensor reading',
                    'Soil temperature sensor reading',
                    'Soil moisture sensor reading',
                    'Electrical conductivity (EC) reading',
                ],
                'quality_notes' => ['No sensor telemetry received for this sample.'],
            ];
        }

        // 1. Audit Soil pH
        $isPhValid = false;
        if ($reading->ph === null) {
            $missingInfo[] = 'Soil pH reading not recorded';
        } elseif ($reading->ph < 0.0 || $reading->ph > 14.0) {
            $qualityNotes[] = "Soil pH reading ({$reading->ph}) is outside physical limits (0–14) and marked invalid.";
        } else {
            $isPhValid = true;
            $usableCount++;
        }

        // 2. Audit Soil Temperature
        $isTempValid = false;
        if ($reading->temperature === null) {
            $missingInfo[] = 'Soil temperature reading not recorded';
        } elseif ($reading->temperature < -10.0 || $reading->temperature > 60.0) {
            $qualityNotes[] = "Soil temperature reading ({$reading->temperature}°C) is outside physical limits (-10°C to 60°C).";
        } else {
            $isTempValid = true;
            $usableCount++;
        }

        // 3. Audit Soil Moisture & Calibration
        $isMoistureCalibrated = $this->cropSuitabilityService->isMoistureCalibrated($reading);
        if ($reading->moisture === null) {
            $missingInfo[] = 'Soil moisture sensor reading missing';
        } elseif (!$isMoistureCalibrated) {
            $missingInfo[] = 'Soil moisture is an uncalibrated raw index; volumetric percentage interpretation withheld';
            $qualityNotes[] = 'Moisture sensor requires gravimetric calibration in field soil before volumetric percentage use.';
        } else {
            $usableCount++;
        }

        // 4. Audit Electrical Conductivity (EC)
        $isEcValid = false;
        if ($reading->ec === null) {
            $missingInfo[] = 'Electrical conductivity (EC) not recorded; salinity risk unevaluated';
        } elseif ($reading->ec < 0.0 || $reading->ec > 30.0) {
            $qualityNotes[] = "Electrical conductivity ({$reading->ec} mS/cm) is outside physical bounds and marked invalid.";
        } else {
            $isEcValid = true;
            $usableCount++;
        }

        // 5. Audit Staleness (only applies to live hardware telemetry, not demo/historical records)
        $isStale = false;
        $isSimulated = in_array(strtolower((string) $reading->data_source), ['demo', 'demonstration', 'simulated']);
        if ($reading->recorded_at && !$isSimulated && $reading->recorded_at->diffInHours(Carbon::now()) > 48) {
            $isStale = true;
            $qualityNotes[] = 'Telemetry reading was recorded over 48 hours ago; values may not represent current root-zone moisture or temperature.';
        }

        // Agronomic Laboratory Limitations (always required for academic rigor)
        $missingInfo[] = 'Macronutrient and micronutrient laboratory analysis (Nitrogen, Phosphorus, Potassium - N-P-K)';
        $missingInfo[] = 'Soil texture classification (sand-silt-clay distribution) and drainage class';
        $missingInfo[] = 'Soil organic matter (SOM) percentage and Cation Exchange Capacity (CEC)';

        return [
            'usable_count' => $usableCount,
            'total_count' => 4,
            'is_ph_valid' => $isPhValid,
            'is_temp_valid' => $isTempValid,
            'is_moisture_calibrated' => $isMoistureCalibrated,
            'is_ec_valid' => $isEcValid,
            'is_stale' => $isStale,
            'missing_parameters' => $missingInfo,
            'quality_notes' => $qualityNotes,
        ];
    }

    /**
     * Perform rule-based agronomic evaluation and persist results in a database transaction.
     *
     * @param SoilSample $sample
     * @param SensorReading|null $reading
     * @return SoilAssessment
     */
    public function evaluateSample(SoilSample $sample, ?SensorReading $reading = null): SoilAssessment
    {
        $reading = $reading ?? $sample->latestReading;

        return DB::transaction(function () use ($sample, $reading) {
            $crops = CropProfile::with('requirements')->get();
            $audit = $this->auditReading($reading);
            $usableCount = $audit['usable_count'];

            // Determine sample assessment status
            $status = match (true) {
                $usableCount >= 3 && !$audit['is_stale'] && $audit['is_ph_valid'] => 'reviewed',
                $usableCount >= 2 => 'preliminary',
                default => 'incomplete',
            };

            // Compose transparent executive summary
            $phText = ($reading && $reading->ph !== null)
                ? "Measured soil pH of {$reading->ph} provides a preliminary baseline comparison with documented crop ranges. "
                : 'Soil pH was not measured. ';

            $moistureText = $audit['is_moisture_calibrated']
                ? 'Calibrated volumetric moisture data is available. '
                : 'Moisture remains an uncalibrated analog index; percentage interpretation is withheld. ';

            $ecText = ($reading && $reading->ec !== null)
                ? "Electrical conductivity is {$reading->ec} {$reading->ec_unit} (measures soluble salts/salinity hazard, not total nutrient abundance). "
                : 'EC was not measured; salinity safety cannot be verified. ';

            $summary = sprintf(
                'Assessment based on %d of 4 monitored parameters. %s%s%sNo definitive soil fertility claims are made without accredited laboratory N-P-K testing.',
                $usableCount,
                $phText,
                $moistureText,
                $ecText
            );

            $limitations = 'This preliminary assessment is derived solely from in-situ electronic sensors. In-situ sensors cannot measure chemical macronutrients (N-P-K), soil texture, compaction, or biological health. Recommendations reflect agronomic compatibility with documented literature thresholds and should not replace accredited laboratory soil analysis or certified agricultural extension consultation.';

            $recommendedSteps = '1. Submit representative core samples to an accredited regional soil laboratory for complete chemical analysis (pH, N-P-K, CEC, SOM). 2. Perform gravimetric field calibration for soil moisture probes. 3. Consult your local municipal agriculturist regarding locally adapted crop varieties and seasonal planting calendars.';

            $assessment = SoilAssessment::updateOrCreate(
                ['soil_sample_id' => $sample->id],
                [
                    'assessed_at' => Carbon::now(),
                    'assessment_method' => 'Rule-based Agronomic Model v1.0 (USDA & FAO Reference)',
                    'status' => $status,
                    'usable_parameters_count' => $usableCount,
                    'total_parameters_count' => 4,
                    'summary' => $summary,
                    'missing_information' => $audit['missing_parameters'],
                    'limitations' => $limitations,
                    'recommended_next_steps' => $recommendedSteps,
                ]
            );

            // Evaluate all crops using the dedicated recommendation engine
            $cropEvaluations = $this->cropSuitabilityService->evaluateAllCrops($crops, $reading, $audit);

            foreach ($cropEvaluations as $eval) {
                AssessmentCropEvaluation::updateOrCreate(
                    [
                        'soil_assessment_id' => $assessment->id,
                        'crop_profile_id' => $eval['crop_id'],
                    ],
                    [
                        'suitability_tier' => $eval['suitability_tier'],
                        'tone' => $eval['tone'],
                        'match_score' => $eval['match_score'],
                        'supporting_factors' => implode('; ', $eval['supporting_factors']) ?: 'No positive supporting factors identified',
                        'limiting_factors' => implode('; ', $eval['limiting_factors']) ?: 'None documented',
                        'ph_status' => $eval['ph_status'],
                        'moisture_status' => $eval['moisture_status'],
                        'temperature_status' => $eval['temperature_status'],
                        'ec_status' => $eval['ec_status'],
                        'recommendation_notes' => json_encode([
                            'confidence_score' => $eval['confidence_score'],
                            'confidence_label' => $eval['confidence_label'],
                            'parameters_evaluated' => $eval['parameters_evaluated'],
                            'missing_measurements' => $eval['missing_measurements'],
                            'recommended_follow_up_tests' => $eval['recommended_follow_up_tests'],
                            'source_citation' => $eval['source_citation'],
                            'scoring_explanation' => $eval['scoring_explanation'],
                        ], JSON_PRETTY_PRINT),
                    ]
                );
            }

            // Sync sample status
            $sample->update(['assessment_status' => $status]);

            return $assessment->load('cropEvaluations.crop.requirements');
        });
    }
}
