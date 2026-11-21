<?php

namespace Tests\Unit;

use App\Models\SensorReading;
use App\Services\FertilizerDosageCalculator;
use InvalidArgumentException;
use Tests\TestCase;

class FertilizerDosageCalculatorTest extends TestCase
{
    protected FertilizerDosageCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new FertilizerDosageCalculator();
    }

    public function test_calculates_npk_grade_dosage_using_config_conversion_and_targets(): void
    {
        // Tomato target N = 120 kg/ha; soil N = 30 mg/kg * 1.95 = 58.5 kg/ha
        // Deficit = 61.5 kg/ha; Urea N fraction = 0.46 => 61.5 / 0.46 = 133.70 kg/ha
        $reading = new SensorReading([
            'nitrogen' => 30.0,
            'phosphorus' => 20.0,
            'potassium' => 30.0,
        ]);

        $result = $this->calculator->calculate('Urea', 'Tomato', $reading);

        $this->assertEqualsWithDelta(133.70, $result['dose_kg_ha'], 0.01);
        $this->assertFalse($result['capped']);
        $this->assertSame(1, $result['num_applications']);
        $this->assertCount(1, $result['split_schedule']);
        $this->assertSame(0, $result['split_schedule'][0]['day_offset']);
        $this->assertEqualsWithDelta(133.70, $result['split_schedule'][0]['dose_kg_ha'], 0.01);
        $this->assertEqualsWithDelta(58.50, $result['nutrient_breakdown']['soil_supply_kg_ha'], 0.01);
        $this->assertEqualsWithDelta(61.50, $result['nutrient_breakdown']['deficit_kg_ha'], 0.01);
    }

    public function test_calculates_dap_mop_ssp_and_npk_grades_from_config(): void
    {
        $reading = new SensorReading([
            'nitrogen' => 40.0,   // 78.0 kg/ha
            'phosphorus' => 20.0, // 39.0 kg/ha
            'potassium' => 20.0,  // 39.0 kg/ha
        ]);

        // DAP for Tomato: P target 80.0 - 39.0 = 41.0 / 0.46 = 89.13 kg/ha
        $dap = $this->calculator->calculate('DAP', 'Tomato', $reading);
        $this->assertEqualsWithDelta(89.13, $dap['dose_kg_ha'], 0.01);

        // MOP for Tomato: K target 120.0 - 39.0 = 81.0 / 0.60 = 135.00 kg/ha
        $mop = $this->calculator->calculate('MOP', 'Tomato', $reading);
        $this->assertEqualsWithDelta(135.00, $mop['dose_kg_ha'], 0.01);

        // SSP for Tomato: P target 80.0 - 39.0 = 41.0 / 0.16 = 256.25 kg/ha (split into 2)
        $ssp = $this->calculator->calculate('SSP', 'Tomato', $reading);
        $this->assertEqualsWithDelta(256.25, $ssp['dose_kg_ha'], 0.01);
        $this->assertTrue($ssp['capped']);
        $this->assertSame(2, $ssp['num_applications']);

        // NPK (14-14-14) for Tomato: N target 120.0 - 78.0 = 42.0 / 0.14 = 300.00 kg/ha (split into 2)
        $npk = $this->calculator->calculate('NPK', 'Tomato', $reading);
        $this->assertEqualsWithDelta(300.00, $npk['dose_kg_ha'], 0.01);
        $this->assertTrue($npk['capped']);
        $this->assertSame(2, $npk['num_applications']);
    }

    public function test_clamps_dose_to_zero_when_soil_supply_exceeds_crop_target(): void
    {
        $reading = new SensorReading([
            'nitrogen' => 100.0, // 195.0 kg/ha > 120.0 kg/ha Tomato target
            'phosphorus' => 60.0,
            'potassium' => 80.0,
        ]);

        $result = $this->calculator->calculate('Urea', 'Tomato', $reading);

        $this->assertSame(0.0, $result['dose_kg_ha']);
        $this->assertFalse($result['capped']);
        $this->assertSame(0, $result['num_applications']);
        $this->assertSame([], $result['split_schedule']);
        $this->assertSame(0.0, $result['nutrient_breakdown']['deficit_kg_ha']);
    }

    public function test_uses_default_crop_target_for_unlisted_crops(): void
    {
        // default N target = 100.0; soil N = 20 * 1.95 = 39.0; deficit = 61.0 / 0.46 = 132.61 kg/ha
        $reading = new SensorReading([
            'nitrogen' => 20.0,
            'phosphorus' => 30.0,
            'potassium' => 30.0,
        ]);

        $result = $this->calculator->calculate('Urea', 'Unlisted Heirloom Crop', $reading);

        $this->assertEqualsWithDelta(132.61, $result['dose_kg_ha'], 0.01);
        $this->assertEqualsWithDelta(100.0, $result['nutrient_breakdown']['target_kg_ha'], 0.01);
    }

    public function test_splits_large_applications_respecting_single_application_cap_and_exact_sum(): void
    {
        // Potato target N = 140.0; soil N = 5 * 1.95 = 9.75; deficit = 130.25 / 0.46 = 283.15 kg/ha
        $reading = new SensorReading([
            'nitrogen' => 5.0,
            'phosphorus' => 10.0,
            'potassium' => 10.0,
        ]);

        $result = $this->calculator->calculate('Urea', 'Potato', $reading);

        $this->assertEqualsWithDelta(283.15, $result['dose_kg_ha'], 0.01);
        $this->assertTrue($result['capped']);
        $this->assertSame(2, $result['num_applications']);

        $sum = 0.0;
        foreach ($result['split_schedule'] as $index => $entry) {
            $this->assertSame($index + 1, $entry['application_number']);
            $this->assertLessThanOrEqual(150.0, $entry['dose_kg_ha']);
            $this->assertNotEmpty($entry['stage']);
            $sum += $entry['dose_kg_ha'];
        }

        $this->assertSame(0, $result['split_schedule'][0]['day_offset']);
        $this->assertSame(21, $result['split_schedule'][1]['day_offset']);
        $this->assertEqualsWithDelta($result['dose_kg_ha'], $sum, 0.001);
    }

    public function test_calculates_amendment_and_micronutrient_doses_from_config_base_rates(): void
    {
        $reading = new SensorReading([
            'nitrogen' => 70.0,
            'phosphorus' => 50.0,
            'potassium' => 50.0,
        ]);

        $zinc = $this->calculator->calculate('Zinc Sulphate', 'Tomato', $reading);
        $this->assertSame(20.0, $zinc['dose_kg_ha']);
        $this->assertFalse($zinc['capped']);
        $this->assertSame(1, $zinc['num_applications']);
        $this->assertEqualsWithDelta(4.2, $zinc['nutrient_breakdown']['delivered_kg_ha']['zn'], 0.01);

        $compost = $this->calculator->calculate('Compost', 'Tomato', $reading);
        $this->assertSame(1000.0, $compost['dose_kg_ha']);
        $this->assertTrue($compost['capped']);
        $this->assertSame(7, $compost['num_applications']);

        $compostSum = array_sum(array_column($compost['split_schedule'], 'dose_kg_ha'));
        $this->assertEqualsWithDelta(1000.0, $compostSum, 0.001);
        foreach ($compost['split_schedule'] as $step) {
            $this->assertLessThanOrEqual(150.0, $step['dose_kg_ha']);
        }
    }

    public function test_rejects_unknown_fertilizer_grade(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate('Unknown Fertilizer X', 'Tomato', new SensorReading([
            'nitrogen' => 40.0,
            'phosphorus' => 30.0,
            'potassium' => 30.0,
        ]));
    }
}
