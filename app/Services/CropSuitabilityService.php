<?php

namespace App\Services;

use App\Models\CropProfile;
use App\Models\CropRequirement;
use App\Models\SensorReading;

class CropSuitabilityService
{
    // Result Tier Constants
    public const TIER_STRONG_MATCH = 'Strong Match';
    public const TIER_POTENTIAL_MATCH = 'Potential Match';
    public const TIER_LIMITED_MATCH = 'Limited Match';
    public const TIER_INSUFFICIENT_DATA = 'Insufficient Data';

    // Tone Badges
    public const TONE_GREEN = 'green';
    public const TONE_BLUE = 'blue';
    public const TONE_AMBER = 'amber';
    public const TONE_GRAY = 'gray';

    // Relative Parameter Scoring Weights for Evaluated Parameters
    // Formula: Score = SUM(Weight_i * FactorScore_i) / SUM(Weight_i)
    // Note: Weights only apply to evaluated, valid parameters. Missing readings are never assigned points.
    public const WEIGHT_PH = 0.45;
    public const WEIGHT_EC = 0.25;
    public const WEIGHT_MOISTURE = 0.15;
    public const WEIGHT_TEMP = 0.15;

    /**
     * Evaluate a single crop against sensor reading data and quality audit metadata.
     *
     * @param CropProfile $crop
     * @param SensorReading|null $reading
     * @param array $audit
     * @return array
     */
    public function evaluateCrop(CropProfile $crop, ?SensorReading $reading, array $audit = []): array
    {
        $req = $crop->requirements;

        // If crop has no documented requirements, fail-safe to Insufficient Data
        if (!$req || $req->min_ph === null || $req->max_ph === null) {
            return $this->buildInsufficientDataResult(
                $crop,
                ['Agronomic requirements reference data is pending or incomplete in the system database.'],
                ['Crop requirements profile missing from agricultural database']
            );
        }

        // If no reading provided or zero usable parameters
        $usableCount = $audit['usable_count'] ?? $this->countUsableParameters($reading);
        if (!$reading || $usableCount < 2) {
            return $this->buildInsufficientDataResult(
                $crop,
                ['Fewer than 2 usable sensor parameters were recorded. Meaningful comparison requires at least baseline pH and EC or temperature.'],
                ['Soil sample measurements missing or incomplete']
            );
        }

        $supportingFactors = [];
        $limitingFactors = [];
        $missingMeasurements = [];
        $evaluatedParameters = [];

        $evaluatedWeights = 0.0;
        $weightedPoints = 0.0;

        $hasSevereLimiting = false;
        $hasLimiting = false;

        // -------------------------------------------------------------
        // 1. Evaluate Soil pH
        // -------------------------------------------------------------
        $phStatus = 'unknown';
        if ($reading->ph === null) {
            $phStatus = 'unknown';
            $missingMeasurements[] = 'Soil pH';
            $limitingFactors[] = 'Soil pH was not measured; nutrient bioavailability and acidity stress cannot be verified.';
            $hasLimiting = true;
        } elseif ($reading->ph < 0.0 || $reading->ph > 14.0) {
            $phStatus = 'invalid';
            $limitingFactors[] = "Measured pH ({$reading->ph}) is outside physical sensor bounds (0–14) and flagged for calibration review.";
            $hasSevereLimiting = true;
        } else {
            $evaluatedParameters[] = 'Soil pH';
            $ph = (float) $reading->ph;
            $minPh = (float) $req->min_ph;
            $maxPh = (float) $req->max_ph;
            $optMin = $req->optimal_ph_min !== null ? (float) $req->optimal_ph_min : $minPh;
            $optMax = $req->optimal_ph_max !== null ? (float) $req->optimal_ph_max : $maxPh;

            $evaluatedWeights += self::WEIGHT_PH;

            if ($ph >= $optMin && $ph <= $optMax) {
                $phStatus = 'optimal';
                $weightedPoints += (self::WEIGHT_PH * 100.0);
                $supportingFactors[] = "Measured soil pH {$ph} is within the documented optimal range ({$optMin}–{$optMax}) for {$crop->name}.";
            } elseif ($ph >= $minPh && $ph <= $maxPh) {
                $phStatus = 'tolerable';
                $weightedPoints += (self::WEIGHT_PH * 75.0);
                $supportingFactors[] = "Measured soil pH {$ph} falls within tolerable growth limits ({$minPh}–{$maxPh}).";
                $limitingFactors[] = "Soil pH {$ph} is slightly outside the preferred optimal range ({$optMin}–{$optMax}).";
            } else {
                $phStatus = 'limiting';
                $weightedPoints += (self::WEIGHT_PH * 15.0);
                $hasLimiting = true;
                $hasSevereLimiting = true;
                if ($ph < $minPh) {
                    $limitingFactors[] = "Soil pH {$ph} is below minimum requirement ({$minPh}) — acidic soil risk; potential aluminum toxicity and restricted phosphorus availability.";
                } else {
                    $limitingFactors[] = "Soil pH {$ph} exceeds maximum threshold ({$maxPh}) — alkaline soil risk; micronutrient immobilization (iron, zinc).";
                }
            }
        }

        // -------------------------------------------------------------
        // 2. Evaluate Electrical Conductivity (EC / Salinity)
        // -------------------------------------------------------------
        // Agronomic Rule: Do NOT treat EC as a direct measurement of all soil nutrients.
        $ecStatus = 'missing';
        if ($reading->ec === null) {
            $ecStatus = 'missing';
            $missingMeasurements[] = 'Electrical Conductivity (EC / Salinity)';
            $limitingFactors[] = 'Electrical conductivity (EC) was not measured; salinity-related yield risk cannot be evaluated.';
        } elseif ($reading->ec < 0.0 || $reading->ec > 30.0) {
            $ecStatus = 'invalid';
            $limitingFactors[] = "Measured EC ({$reading->ec}) is outside physical range (0–30 mS/cm) and flagged as invalid.";
            $hasSevereLimiting = true;
        } else {
            $evaluatedParameters[] = 'Electrical Conductivity';
            $ec = (float) $reading->ec;
            $maxEc = $req->max_ec !== null ? (float) $req->max_ec : null;

            if ($maxEc !== null) {
                $evaluatedWeights += self::WEIGHT_EC;

                if ($ec <= $maxEc) {
                    $ecStatus = 'tolerable';
                    $weightedPoints += (self::WEIGHT_EC * 100.0);
                    $supportingFactors[] = "Electrical conductivity {$ec} {$req->ec_unit} is within safe salinity tolerance (threshold <= {$maxEc} {$req->ec_unit}). (Note: EC indicates soluble salts/salinity risk, not individual N-P-K nutrient abundance).";
                } else {
                    $ecStatus = 'limiting';
                    $weightedPoints += (self::WEIGHT_EC * 15.0);
                    $hasLimiting = true;
                    $hasSevereLimiting = true;
                    $limitingFactors[] = "Electrical conductivity {$ec} {$req->ec_unit} exceeds safe salinity tolerance limit ({$maxEc} {$req->ec_unit}) — risk of osmotic root stress, stunted vegetative growth, and leaf burn.";
                }
            } else {
                $ecStatus = 'tolerable';
                $supportingFactors[] = "Electrical conductivity measured at {$ec} {$req->ec_unit}; no specific salinity threshold is published for this crop.";
            }
        }

        // -------------------------------------------------------------
        // 3. Evaluate Soil Moisture
        // -------------------------------------------------------------
        // Agronomic Rule: Do not assume raw sensor values are calibrated.
        // Do not infer moisture percentage from raw ADC values.
        $moistureStatus = 'unknown';
        $isCalibrated = $this->isMoistureCalibrated($reading);

        if ($reading->moisture === null) {
            $moistureStatus = 'unknown';
            $missingMeasurements[] = 'Soil moisture';
            $limitingFactors[] = 'Soil moisture reading is missing.';
        } elseif (!$isCalibrated) {
            $moistureStatus = 'uncalibrated';
            $missingMeasurements[] = 'Calibrated volumetric moisture (% VWC)';
            $limitingFactors[] = "Soil moisture is reported as an uncalibrated raw index ({$reading->moisture} {$reading->moisture_unit}); volumetric water content (% VWC) cannot be compared against crop requirements without soil-specific calibration.";
        } else {
            $evaluatedParameters[] = 'Soil Moisture';
            $moisture = (float) $reading->moisture;

            if ($moisture < 0.0 || $moisture > 100.0) {
                $moistureStatus = 'invalid';
                $limitingFactors[] = "Measured moisture ({$moisture}%) exceeds physical range (0–100%) and flagged for sensor review.";
                $hasSevereLimiting = true;
            } elseif ($req->min_moisture !== null && $req->max_moisture !== null) {
                $evaluatedWeights += self::WEIGHT_MOISTURE;
                $minM = (float) $req->min_moisture;
                $maxM = (float) $req->max_moisture;
                $optMinM = $req->optimal_moisture_min !== null ? (float) $req->optimal_moisture_min : $minM;
                $optMaxM = $req->optimal_moisture_max !== null ? (float) $req->optimal_moisture_max : $maxM;

                if ($moisture >= $optMinM && $moisture <= $optMaxM) {
                    $moistureStatus = 'optimal';
                    $weightedPoints += (self::WEIGHT_MOISTURE * 100.0);
                    $supportingFactors[] = "Calibrated volumetric moisture {$moisture}% is in the optimal range ({$optMinM}–{$optMaxM}%).";
                } elseif ($moisture >= $minM && $moisture <= $maxM) {
                    $moistureStatus = 'tolerable';
                    $weightedPoints += (self::WEIGHT_MOISTURE * 75.0);
                    $supportingFactors[] = "Calibrated volumetric moisture {$moisture}% is within tolerable range ({$minM}–{$maxM}%).";
                } else {
                    $moistureStatus = 'limiting';
                    $weightedPoints += (self::WEIGHT_MOISTURE * 25.0);
                    $hasLimiting = true;
                    if ($moisture < $minM) {
                        $limitingFactors[] = "Soil moisture {$moisture}% is below minimum requirement ({$minM}%) — deficit stress; supplemental irrigation required.";
                    } else {
                        $limitingFactors[] = "Soil moisture {$moisture}% exceeds maximum tolerable ({$maxM}%) — waterlogged risk; potential aeration deficit and root rot.";
                    }
                }
            } else {
                $moistureStatus = 'tolerable';
                $supportingFactors[] = "Calibrated volumetric moisture measured at {$moisture}%.";
            }
        }

        // -------------------------------------------------------------
        // 4. Evaluate Soil Temperature
        // -------------------------------------------------------------
        $tempStatus = 'unknown';
        if ($reading->temperature === null) {
            $tempStatus = 'unknown';
            $missingMeasurements[] = 'Soil temperature';
        } elseif ($reading->temperature < -10.0 || $reading->temperature > 60.0) {
            $tempStatus = 'invalid';
            $limitingFactors[] = "Measured temperature ({$reading->temperature}°C) is outside physical bounds and flagged for review.";
            $hasSevereLimiting = true;
        } else {
            $temp = (float) $reading->temperature;

            if ($req->min_temperature !== null && $req->max_temperature !== null) {
                $evaluatedParameters[] = 'Soil Temperature';
                $evaluatedWeights += self::WEIGHT_TEMP;
                $minT = (float) $req->min_temperature;
                $maxT = (float) $req->max_temperature;
                $optMinT = $req->optimal_temperature_min !== null ? (float) $req->optimal_temperature_min : $minT;
                $optMaxT = $req->optimal_temperature_max !== null ? (float) $req->optimal_temperature_max : $maxT;

                if ($temp >= $optMinT && $temp <= $optMaxT) {
                    $tempStatus = 'optimal';
                    $weightedPoints += (self::WEIGHT_TEMP * 100.0);
                    $supportingFactors[] = "Soil temperature {$temp}°C is within optimal range ({$optMinT}–{$optMaxT}°C).";
                } elseif ($temp >= $minT && $temp <= $maxT) {
                    $tempStatus = 'tolerable';
                    $weightedPoints += (self::WEIGHT_TEMP * 75.0);
                    $supportingFactors[] = "Soil temperature {$temp}°C is within tolerable range ({$minT}–{$maxT}°C).";
                } else {
                    $tempStatus = 'limiting';
                    $weightedPoints += (self::WEIGHT_TEMP * 30.0);
                    $hasLimiting = true;
                    if ($temp < $minT) {
                        $limitingFactors[] = "Soil temperature {$temp}°C is below minimum requirement ({$minT}°C) — cold stress may retard germination and nutrient uptake.";
                    } else {
                        $limitingFactors[] = "Soil temperature {$temp}°C exceeds maximum threshold ({$maxT}°C) — heat stress.";
                    }
                }
            } else {
                $tempStatus = 'tolerable';
                $supportingFactors[] = "Soil temperature measured at {$temp}°C.";
            }
        }

        // -------------------------------------------------------------
        // 5. Classification of Crop Result States
        // Explicit Rules:
        // - Insufficient Data: < 2 usable parameters, or missing pH
        // - Strong Match: >= 3 usable parameters, pH optimal, moisture calibrated (if measured), no limiting factors
        // - Potential Match: pH within tolerable bounds, no severe limiting factor
        // - Limited Match: At least one measured parameter is limiting/out of bounds
        // -------------------------------------------------------------
        if ($phStatus === 'unknown' || $phStatus === 'invalid' || $usableCount < 2) {
            $tier = self::TIER_INSUFFICIENT_DATA;
            $tone = self::TONE_GRAY;
            $score = null;
        } elseif ($hasSevereLimiting || ($hasLimiting && $phStatus === 'limiting')) {
            $tier = self::TIER_LIMITED_MATCH;
            $tone = self::TONE_AMBER;
            $score = $evaluatedWeights > 0 ? round($weightedPoints / $evaluatedWeights, 1) : null;
        } elseif ($usableCount >= 3 && $phStatus === 'optimal' && !$hasLimiting && $isCalibrated) {
            $tier = self::TIER_STRONG_MATCH;
            $tone = self::TONE_GREEN;
            $score = $evaluatedWeights > 0 ? round($weightedPoints / $evaluatedWeights, 1) : null;
        } else {
            $tier = self::TIER_POTENTIAL_MATCH;
            $tone = self::TONE_BLUE;
            $score = $evaluatedWeights > 0 ? round($weightedPoints / $evaluatedWeights, 1) : null;
        }

        // Evidence completeness computation
        $confidencePct = round(($usableCount / 4) * 100);
        $confidenceLabel = match (true) {
            $usableCount >= 4 => 'High Evidence Completeness (4/4 parameters evaluated)',
            $usableCount === 3 => 'Moderate Evidence Completeness (3/4 parameters evaluated)',
            $usableCount === 2 => 'Preliminary Evidence Completeness (2/4 parameters evaluated)',
            default => 'Insufficient Evidence (< 2 parameters)',
        };

        // Standard recommended follow-up tests
        $recommendedFollowUp = [
            'Accredited laboratory analysis for primary macronutrients (Nitrogen, Phosphorus, Potassium - N-P-K).',
            'Laboratory soil texture analysis (hydrometer test) and drainage percolation assessment.',
            'Measurement of Cation Exchange Capacity (CEC) and soil organic matter (SOM) percentage.',
        ];

        if (!$isCalibrated && $reading->moisture !== null) {
            $recommendedFollowUp[] = 'Soil-specific gravimetric calibration for the capacitive moisture probe.';
        }

        if ($reading->ec === null) {
            $recommendedFollowUp[] = 'Laboratory 1:5 soil:water suspension electrical conductivity test to confirm salinity safety.';
        }

        if ($phStatus === 'limiting') {
            $recommendedFollowUp[] = 'Soil buffer pH test to calculate accurate agricultural lime or sulfur amendment requirement.';
        }

        $scoringExplanation = $score !== null
            ? sprintf(
                'Rule-based score of %s%% calculated from %d valid parameter(s). This is an agronomic threshold compatibility index, NOT a probability of crop yield.',
                $score,
                count($evaluatedParameters)
            )
            : 'Scoring withheld due to insufficient or uncalibrated primary parameters.';

        return [
            'crop_id' => $crop->id,
            'crop_name' => $crop->name,
            'scientific_name' => $crop->scientific_name,
            'category' => $crop->category,
            'image_url' => $crop->image_url,
            'suitability_tier' => $tier,
            'tone' => $tone,
            'match_score' => $score,
            'confidence_score' => $confidencePct,
            'confidence_label' => $confidenceLabel,
            'parameters_evaluated' => $evaluatedParameters,
            'supporting_factors' => $supportingFactors,
            'limiting_factors' => $limitingFactors,
            'missing_measurements' => $missingMeasurements,
            'ph_status' => $phStatus,
            'moisture_status' => $moistureStatus,
            'temperature_status' => $tempStatus,
            'ec_status' => $ecStatus,
            'recommended_follow_up_tests' => $recommendedFollowUp,
            'source_citation' => $req->source_citation ?? 'FAO Reference Guidelines',
            'source_url' => $req->source_url,
            'applicability_notes' => $req->applicability_notes,
            'limitations_notes' => $req->limitations_notes,
            'scoring_explanation' => $scoringExplanation,
        ];
    }

    /**
     * Evaluate a list of crops and rank them by suitability tier and score.
     *
     * @param iterable $crops
     * @param SensorReading|null $reading
     * @param array $audit
     * @return array
     */
    public function evaluateAllCrops(iterable $crops, ?SensorReading $reading, array $audit = []): array
    {
        $results = [];

        foreach ($crops as $crop) {
            $results[] = $this->evaluateCrop($crop, $reading, $audit);
        }

        // Rank order: Strong Match > Potential Match > Limited Match > Insufficient Data
        $tierOrder = [
            self::TIER_STRONG_MATCH => 1,
            self::TIER_POTENTIAL_MATCH => 2,
            self::TIER_LIMITED_MATCH => 3,
            self::TIER_INSUFFICIENT_DATA => 4,
        ];

        usort($results, function ($a, $b) use ($tierOrder) {
            $rankA = $tierOrder[$a['suitability_tier']] ?? 99;
            $rankB = $tierOrder[$b['suitability_tier']] ?? 99;

            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            // Sub-sort by score descending
            $scoreA = $a['match_score'] ?? -1.0;
            $scoreB = $b['match_score'] ?? -1.0;

            return $scoreB <=> $scoreA;
        });

        return $results;
    }

    /**
     * Count usable parameters from a reading.
     */
    public function countUsableParameters(?SensorReading $reading): int
    {
        if (!$reading) {
            return 0;
        }

        $count = 0;
        if ($reading->ph !== null && $reading->ph >= 0.0 && $reading->ph <= 14.0) {
            $count++;
        }
        if ($reading->temperature !== null && $reading->temperature >= -10.0 && $reading->temperature <= 60.0) {
            $count++;
        }
        if ($reading->ec !== null && $reading->ec >= 0.0 && $reading->ec <= 30.0) {
            $count++;
        }
        if ($reading->moisture !== null && $this->isMoistureCalibrated($reading)) {
            $count++;
        }

        return $count;
    }

    /**
     * Check whether moisture is calibrated volumetric water content.
     */
    public function isMoistureCalibrated(?SensorReading $reading): bool
    {
        if (!$reading || $reading->moisture === null) {
            return false;
        }

        $unit = strtolower(trim($reading->moisture_unit ?? ''));
        if ($unit === 'raw index' || $unit === 'adc' || $unit === 'raw' || empty($unit)) {
            return false;
        }

        $meta = $reading->calibration_metadata;
        if (is_array($meta) && isset($meta['calibrated']) && $meta['calibrated'] === false) {
            return false;
        }

        return true;
    }

    /**
     * Helper to construct a standardized Insufficient Data evaluation.
     */
    private function buildInsufficientDataResult(CropProfile $crop, array $limitingReasons, array $missing): array
    {
        return [
            'crop_id' => $crop->id,
            'crop_name' => $crop->name,
            'scientific_name' => $crop->scientific_name,
            'category' => $crop->category,
            'image_url' => $crop->image_url,
            'suitability_tier' => self::TIER_INSUFFICIENT_DATA,
            'tone' => self::TONE_GRAY,
            'match_score' => null,
            'confidence_score' => 0.0,
            'confidence_label' => 'Insufficient Data (< 2 usable parameters)',
            'parameters_evaluated' => [],
            'supporting_factors' => [],
            'limiting_factors' => $limitingReasons,
            'missing_measurements' => $missing,
            'ph_status' => 'unknown',
            'moisture_status' => 'unknown',
            'temperature_status' => 'unknown',
            'ec_status' => 'missing',
            'recommended_follow_up_tests' => [
                'Record valid soil pH and electrical conductivity sensor readings.',
                'Obtain certified agricultural laboratory soil test.',
            ],
            'source_citation' => $crop->requirements?->source_citation ?? 'Pending Citation',
            'source_url' => $crop->requirements?->source_url,
            'applicability_notes' => $crop->requirements?->applicability_notes,
            'limitations_notes' => $crop->requirements?->limitations_notes,
            'scoring_explanation' => 'Scoring withheld because required reference or sensor data is missing.',
        ];
    }
}
