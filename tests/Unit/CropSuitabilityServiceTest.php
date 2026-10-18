<?php

namespace Tests\Unit;

use App\Models\CropProfile;
use App\Models\CropRequirement;
use App\Models\SensorReading;
use App\Services\CropSuitabilityService;
use Tests\TestCase;

class CropSuitabilityServiceTest extends TestCase
{
    protected CropSuitabilityService $service;
    protected CropProfile $tomato;
    protected CropRequirement $tomatoReq;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CropSuitabilityService();

        // Standard Tomato fixture based on FAO Paper 29 reference
        $this->tomato = new CropProfile([
            'id' => 1,
            'name' => 'Tomato',
            'scientific_name' => 'Solanum lycopersicum',
            'category' => 'Fruit vegetables',
        ]);

        $this->tomatoReq = new CropRequirement([
            'min_ph' => 5.5,
            'max_ph' => 7.5,
            'optimal_ph_min' => 6.0,
            'optimal_ph_max' => 6.8,
            'min_moisture' => 45.0,
            'max_moisture' => 75.0,
            'optimal_moisture_min' => 50.0,
            'optimal_moisture_max' => 70.0,
            'moisture_unit' => '% VWC',
            'min_temperature' => 18.0,
            'max_temperature' => 30.0,
            'optimal_temperature_min' => 20.0,
            'optimal_temperature_max' => 27.0,
            'max_ec' => 2.5,
            'ec_unit' => 'mS/cm',
            'source_citation' => 'FAO Irrigation and Drainage Paper 29',
        ]);

        $this->tomato->setRelation('requirements', $this->tomatoReq);
    }

    /**
     * Test 1: Missing pH cannot produce a Strong Match and is flagged as unknown.
     */
    public function test_missing_ph_is_flagged_and_scoring_withheld(): void
    {
        $reading = new SensorReading([
            'ph' => null, // Missing pH
            'temperature' => 24.0,
            'moisture' => 55.0,
            'moisture_unit' => '% VWC',
            'ec' => 1.2,
            'ec_unit' => 'mS/cm',
            'is_valid' => true,
        ]);

        $result = $this->service->evaluateCrop($this->tomato, $reading);

        $this->assertEquals('unknown', $result['ph_status']);
        $this->assertContains('Soil pH', $result['missing_measurements']);
        $this->assertNotEquals(CropSuitabilityService::TIER_STRONG_MATCH, $result['suitability_tier']);
        $this->assertEquals(CropSuitabilityService::TIER_INSUFFICIENT_DATA, $result['suitability_tier']);
        $this->assertNull($result['match_score']);
    }

    /**
     * Test 2: Missing EC is reported and notes salinity risk is unassessed without fake zero.
     */
    public function test_missing_ec_flags_unassessed_salinity_risk(): void
    {
        $reading = new SensorReading([
            'ph' => 6.4,
            'temperature' => 24.0,
            'moisture' => 55.0,
            'moisture_unit' => '% VWC',
            'ec' => null, // Missing EC
            'is_valid' => true,
        ]);

        $result = $this->service->evaluateCrop($this->tomato, $reading);

        $this->assertEquals('missing', $result['ec_status']);
        $this->assertContains('Electrical Conductivity (EC / Salinity)', $result['missing_measurements']);
        $this->assertContains('Electrical conductivity (EC) was not measured; salinity-related yield risk cannot be evaluated.', $result['limiting_factors']);
    }

    /**
     * Test 3: Uncalibrated raw moisture is NOT inferred as percentage and cannot produce Strong Match.
     */
    public function test_uncalibrated_moisture_is_not_treated_as_percentage(): void
    {
        $reading = new SensorReading([
            'ph' => 6.4,
            'temperature' => 24.0,
            'moisture' => 450.0, // Raw analog ADC value
            'moisture_unit' => 'raw index',
            'ec' => 1.2,
            'is_valid' => true,
            'calibration_metadata' => ['calibrated' => false],
        ]);

        $result = $this->service->evaluateCrop($this->tomato, $reading);

        $this->assertEquals('uncalibrated', $result['moisture_status']);
        $this->assertContains('Calibrated volumetric moisture (% VWC)', $result['missing_measurements']);
        // Uncalibrated moisture disqualifies sample from Strong Match
        $this->assertNotEquals(CropSuitabilityService::TIER_STRONG_MATCH, $result['suitability_tier']);
        $this->assertEquals(CropSuitabilityService::TIER_POTENTIAL_MATCH, $result['suitability_tier']);
    }

    /**
     * Test 4: Invalid sensor readings (out of physical bounds) are flagged for review.
     */
    public function test_invalid_sensor_readings_are_flagged_for_review(): void
    {
        $reading = new SensorReading([
            'ph' => 16.5, // Impossible pH (> 14)
            'temperature' => 24.0,
            'moisture' => 55.0,
            'moisture_unit' => '% VWC',
            'ec' => 1.2,
            'is_valid' => false,
        ]);

        $result = $this->service->evaluateCrop($this->tomato, $reading);

        $this->assertEquals('invalid', $result['ph_status']);
        $this->assertStringContainsString('outside physical sensor bounds', implode('; ', $result['limiting_factors']));
        $this->assertEquals(CropSuitabilityService::TIER_INSUFFICIENT_DATA, $result['suitability_tier']);
    }

    /**
     * Test 5: Parameter values inside and outside crop-specific reference ranges.
     */
    public function test_parameter_values_inside_and_outside_crop_ranges(): void
    {
        // 5A: Inside optimal pH (6.0 - 6.8) and safe EC (<= 2.5)
        $readingOptimal = new SensorReading([
            'ph' => 6.5,
            'temperature' => 24.0,
            'moisture' => 60.0,
            'moisture_unit' => '% VWC',
            'ec' => 1.5,
            'is_valid' => true,
        ]);

        $resultOptimal = $this->service->evaluateCrop($this->tomato, $readingOptimal);
        $this->assertEquals('optimal', $resultOptimal['ph_status']);
        $this->assertEquals('tolerable', $resultOptimal['ec_status']);
        $this->assertStringContainsString('within the documented optimal range', implode('; ', $resultOptimal['supporting_factors']));

        // 5B: Outside range - Acidic pH 4.8 (< 5.5) and excessive salinity EC 4.2 (> 2.5)
        $readingOutside = new SensorReading([
            'ph' => 4.8,
            'temperature' => 24.0,
            'moisture' => 60.0,
            'moisture_unit' => '% VWC',
            'ec' => 4.2,
            'is_valid' => true,
        ]);

        $resultOutside = $this->service->evaluateCrop($this->tomato, $readingOutside);
        $this->assertEquals('limiting', $resultOutside['ph_status']);
        $this->assertEquals('limiting', $resultOutside['ec_status']);
        $this->assertEquals(CropSuitabilityService::TIER_LIMITED_MATCH, $resultOutside['suitability_tier']);
        $this->assertStringContainsString('below minimum requirement', implode('; ', $resultOutside['limiting_factors']));
        $this->assertStringContainsString('exceeds safe salinity tolerance', implode('; ', $resultOutside['limiting_factors']));
    }

    /**
     * Test 6: Incomplete crop requirements fail-safe to Insufficient Data without inventing values.
     */
    public function test_incomplete_crop_requirements_prevent_false_scoring(): void
    {
        $incompleteCrop = new CropProfile([
            'id' => 99,
            'name' => 'Hypothetical Crop',
        ]);
        // No requirements relation set

        $reading = new SensorReading([
            'ph' => 6.5,
            'temperature' => 24.0,
            'moisture' => 50.0,
            'moisture_unit' => '% VWC',
            'ec' => 1.0,
            'is_valid' => true,
        ]);

        $result = $this->service->evaluateCrop($incompleteCrop, $reading);

        $this->assertEquals(CropSuitabilityService::TIER_INSUFFICIENT_DATA, $result['suitability_tier']);
        $this->assertNull($result['match_score']);
        $this->assertContains('Crop requirements profile missing from agricultural database', $result['missing_measurements']);
    }

    /**
     * Test 7: Strong Match vs Limited Match classification and scoring.
     */
    public function test_strong_match_and_limited_match_classifications(): void
    {
        // 7A: Ideal sample meeting all criteria -> Strong Match
        $strongReading = new SensorReading([
            'ph' => 6.4,
            'temperature' => 24.0,
            'moisture' => 60.0,
            'moisture_unit' => '% VWC',
            'ec' => 1.2,
            'is_valid' => true,
        ]);

        $strongResult = $this->service->evaluateCrop($this->tomato, $strongReading);

        $this->assertEquals(CropSuitabilityService::TIER_STRONG_MATCH, $strongResult['suitability_tier']);
        $this->assertEquals(CropSuitabilityService::TONE_GREEN, $strongResult['tone']);
        $this->assertNotNull($strongResult['match_score']);
        $this->assertGreaterThanOrEqual(90.0, $strongResult['match_score']);

        // 7B: Stressed sample with high salinity -> Limited Match
        $limitedReading = new SensorReading([
            'ph' => 6.4,
            'temperature' => 24.0,
            'moisture' => 60.0,
            'moisture_unit' => '% VWC',
            'ec' => 3.5, // Exceeds Tomato threshold 2.5
            'is_valid' => true,
        ]);

        $limitedResult = $this->service->evaluateCrop($this->tomato, $limitedReading);

        $this->assertEquals(CropSuitabilityService::TIER_LIMITED_MATCH, $limitedResult['suitability_tier']);
        $this->assertEquals(CropSuitabilityService::TONE_AMBER, $limitedResult['tone']);
        $this->assertLessThan($strongResult['match_score'], $limitedResult['match_score']);
    }

    /**
     * Test 8: Samples with no usable measurements return Insufficient Data.
     */
    public function test_samples_with_no_usable_measurements_return_insufficient_data(): void
    {
        $blankReading = new SensorReading([
            'ph' => null,
            'temperature' => null,
            'moisture' => null,
            'ec' => null,
            'is_valid' => true,
        ]);

        $result = $this->service->evaluateCrop($this->tomato, $blankReading);

        $this->assertEquals(CropSuitabilityService::TIER_INSUFFICIENT_DATA, $result['suitability_tier']);
        $this->assertEquals(CropSuitabilityService::TONE_GRAY, $result['tone']);
        $this->assertNull($result['match_score']);
        $this->assertEquals(0.0, $result['confidence_score']);
        $this->assertEmpty($result['parameters_evaluated']);
    }
}
