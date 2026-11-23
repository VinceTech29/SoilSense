<?php

namespace Tests\Unit;

use App\Models\SensorReading;
use App\Services\FertilizerDosageCalculator;
use Illuminate\Support\Facades\Config;
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

    public function test_calculates_crop_baseline_dosage_without_soil_npk_subtraction(): void
    {
        // String Beans N target = 30.0 kg/ha; Urea N fraction = 0.46 => 30.0 / 0.46 = 65.22 kg/ha
        $reading = new SensorReading([
            'ph' => 6.4,
            'ec' => 1.1,
            'moisture' => 50.0,
            'temperature' => 26.0,
        ]);

        $result = $this->calculator->calculate('Urea', 'String Beans', $reading);

        $this->assertEqualsWithDelta(65.22, $result['dose_kg_ha'], 0.01);
        $this->assertFalse($result['capped']);
        $this->assertSame(1, $result['num_applications']);
        $this->assertCount(1, $result['split_schedule']);
        $this->assertSame(0, $result['split_schedule'][0]['day_offset']);
        $this->assertEqualsWithDelta(65.22, $result['split_schedule'][0]['dose_kg_ha'], 0.01);
        $this->assertSame('crop_baseline_without_soil_npk', $result['nutrient_breakdown']['basis']);
        $this->assertEqualsWithDelta(30.0, $result['nutrient_breakdown']['target_kg_ha'], 0.01);
    }

    public function test_calculates_urea_dap_mop_ssp_and_npk_grades_from_crop_targets(): void
    {
        $reading = new SensorReading(['ph' => 6.5, 'ec' => 1.2, 'moisture' => 52.0, 'temperature' => 25.0]);

        // Urea for Tomato: N target 120.0 / 0.46 = 260.87 kg/ha (split into 2)
        $urea = $this->calculator->calculate('Urea', 'Tomato', $reading);
        $this->assertEqualsWithDelta(260.87, $urea['dose_kg_ha'], 0.01);
        $this->assertTrue($urea['capped']);
        $this->assertSame(2, $urea['num_applications']);
        $this->assertSame('crop_baseline_without_soil_npk', $urea['nutrient_breakdown']['basis']);

        // DAP for Tomato: P target 80.0 / 0.46 = 173.91 kg/ha (split into 2)
        $dap = $this->calculator->calculate('DAP', 'Tomato', $reading);
        $this->assertEqualsWithDelta(173.91, $dap['dose_kg_ha'], 0.01);
        $this->assertTrue($dap['capped']);
        $this->assertSame(2, $dap['num_applications']);

        // MOP for Tomato: K target 120.0 / 0.60 = 200.00 kg/ha (split into 2)
        $mop = $this->calculator->calculate('MOP', 'Tomato', $reading);
        $this->assertEqualsWithDelta(200.00, $mop['dose_kg_ha'], 0.01);
        $this->assertTrue($mop['capped']);
        $this->assertSame(2, $mop['num_applications']);

        // SSP for Tomato: P target 80.0 / 0.16 = 500.00 kg/ha (split into 4)
        $ssp = $this->calculator->calculate('SSP', 'Tomato', $reading);
        $this->assertEqualsWithDelta(500.00, $ssp['dose_kg_ha'], 0.01);
        $this->assertTrue($ssp['capped']);
        $this->assertSame(4, $ssp['num_applications']);

        // NPK (14-14-14) for Tomato: N target 120.0 / 0.14 = 857.14 kg/ha (split into 6)
        $npk = $this->calculator->calculate('NPK', 'Tomato', $reading);
        $this->assertEqualsWithDelta(857.14, $npk['dose_kg_ha'], 0.01);
        $this->assertTrue($npk['capped']);
        $this->assertSame(6, $npk['num_applications']);
    }

    public function test_returns_zero_dose_when_crop_target_is_zero(): void
    {
        Config::set('fertilizer.crop_targets.ZeroTargetCrop', ['n' => 0.0, 'p' => 0.0, 'k' => 0.0]);

        $result = $this->calculator->calculate('Urea', 'ZeroTargetCrop');

        $this->assertSame(0.0, $result['dose_kg_ha']);
        $this->assertFalse($result['capped']);
        $this->assertSame(0, $result['num_applications']);
        $this->assertSame([], $result['split_schedule']);
        $this->assertSame('crop_baseline_without_soil_npk', $result['nutrient_breakdown']['basis']);
    }

    public function test_uses_default_crop_target_for_unlisted_crops(): void
    {
        // default N target = 100.0 / 0.46 = 217.39 kg/ha
        $result = $this->calculator->calculate('Urea', 'Unlisted Heirloom Crop');

        $this->assertEqualsWithDelta(217.39, $result['dose_kg_ha'], 0.01);
        $this->assertEqualsWithDelta(100.0, $result['nutrient_breakdown']['target_kg_ha'], 0.01);
        $this->assertSame('crop_baseline_without_soil_npk', $result['nutrient_breakdown']['basis']);
    }

    public function test_splits_large_applications_respecting_single_application_cap_and_exact_sum(): void
    {
        // Potato target N = 140.0 / 0.46 = 304.35 kg/ha -> 3 applications <= 150.0 kg/ha
        $result = $this->calculator->calculate('Urea', 'Potato');

        $this->assertEqualsWithDelta(304.35, $result['dose_kg_ha'], 0.01);
        $this->assertTrue($result['capped']);
        $this->assertSame(3, $result['num_applications']);

        $sum = 0.0;
        foreach ($result['split_schedule'] as $index => $entry) {
            $this->assertSame($index + 1, $entry['application_number']);
            $this->assertLessThanOrEqual(150.0, $entry['dose_kg_ha']);
            $this->assertNotEmpty($entry['stage']);
            $sum += $entry['dose_kg_ha'];
        }

        $this->assertSame(0, $result['split_schedule'][0]['day_offset']);
        $this->assertSame(21, $result['split_schedule'][1]['day_offset']);
        $this->assertSame(42, $result['split_schedule'][2]['day_offset']);
        $this->assertEqualsWithDelta($result['dose_kg_ha'], $sum, 0.001);
    }

    public function test_calculates_amendment_and_micronutrient_doses_from_config_base_rates(): void
    {
        $zinc = $this->calculator->calculate('Zinc Sulphate', 'Tomato');
        $this->assertSame(20.0, $zinc['dose_kg_ha']);
        $this->assertFalse($zinc['capped']);
        $this->assertSame(1, $zinc['num_applications']);
        $this->assertSame('crop_baseline_without_soil_npk', $zinc['nutrient_breakdown']['basis']);
        $this->assertEqualsWithDelta(4.2, $zinc['nutrient_breakdown']['delivered_kg_ha']['zn'], 0.01);

        $compost = $this->calculator->calculate('Compost', 'Tomato');
        $this->assertSame(1000.0, $compost['dose_kg_ha']);
        $this->assertTrue($compost['capped']);
        $this->assertSame(7, $compost['num_applications']);
        $this->assertSame('crop_baseline_without_soil_npk', $compost['nutrient_breakdown']['basis']);

        $compostSum = array_sum(array_column($compost['split_schedule'], 'dose_kg_ha'));
        $this->assertEqualsWithDelta(1000.0, $compostSum, 0.001);
        foreach ($compost['split_schedule'] as $step) {
            $this->assertLessThanOrEqual(150.0, $step['dose_kg_ha']);
        }
    }

    public function test_rejects_unknown_fertilizer_grade(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate('Unknown Fertilizer X', 'Tomato');
    }
}
