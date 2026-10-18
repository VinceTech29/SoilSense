<?php

namespace Tests\Unit;

use App\Models\SensorReading;
use App\Services\CropSuitabilityService;
use App\Services\SoilAssessmentService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SoilAssessmentServiceTest extends TestCase
{
    protected SoilAssessmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SoilAssessmentService(new CropSuitabilityService());
    }

    /**
     * Test audit with all 4 calibrated and valid sensor channels.
     */
    public function test_audit_identifies_all_four_usable_channels(): void
    {
        $reading = new SensorReading([
            'ph' => 6.5,
            'temperature' => 25.0,
            'moisture' => 50.0,
            'moisture_unit' => '% VWC',
            'ec' => 1.2,
            'ec_unit' => 'mS/cm',
            'recorded_at' => Carbon::now(),
            'data_source' => 'live_sensor',
            'calibration_metadata' => ['calibrated' => true],
        ]);

        $audit = $this->service->auditReading($reading);

        $this->assertEquals(4, $audit['usable_count']);
        $this->assertTrue($audit['is_ph_valid']);
        $this->assertTrue($audit['is_temp_valid']);
        $this->assertTrue($audit['is_moisture_calibrated']);
        $this->assertTrue($audit['is_ec_valid']);
        $this->assertFalse($audit['is_stale']);
    }

    /**
     * Test audit flags uncalibrated raw index moisture.
     */
    public function test_audit_flags_uncalibrated_analog_moisture(): void
    {
        $reading = new SensorReading([
            'ph' => 6.2,
            'temperature' => 28.0,
            'moisture' => 31.0,
            'moisture_unit' => 'raw index',
            'ec' => null,
            'recorded_at' => Carbon::now(),
        ]);

        $audit = $this->service->auditReading($reading);

        $this->assertEquals(2, $audit['usable_count']); // Only pH and temp
        $this->assertFalse($audit['is_moisture_calibrated']);
        $this->assertFalse($audit['is_ec_valid']);
        $this->assertContains('Soil moisture is an uncalibrated raw index; volumetric percentage interpretation withheld', $audit['missing_parameters']);
        $this->assertContains('Electrical conductivity (EC) not recorded; salinity risk unevaluated', $audit['missing_parameters']);
    }

    /**
     * Test audit detects stale readings older than 48 hours for live sensors.
     */
    public function test_audit_detects_stale_telemetry(): void
    {
        $reading = new SensorReading([
            'ph' => 6.4,
            'temperature' => 24.0,
            'moisture' => 55.0,
            'moisture_unit' => '% VWC',
            'ec' => 1.2,
            'recorded_at' => Carbon::now()->subHours(72),
            'data_source' => 'live_sensor',
        ]);

        $audit = $this->service->auditReading($reading);

        $this->assertTrue($audit['is_stale']);
        $this->assertStringContainsString('recorded over 48 hours ago', implode('; ', $audit['quality_notes']));
    }

    /**
     * Test audit flags physically impossible sensor values.
     */
    public function test_audit_flags_out_of_bounds_sensor_values(): void
    {
        $reading = new SensorReading([
            'ph' => -2.0, // Invalid (< 0)
            'temperature' => 85.0, // Invalid (> 60)
            'moisture' => 50.0,
            'moisture_unit' => '% VWC',
            'ec' => 45.0, // Invalid (> 30)
            'recorded_at' => Carbon::now(),
        ]);

        $audit = $this->service->auditReading($reading);

        $this->assertEquals(1, $audit['usable_count']); // Only calibrated moisture
        $this->assertFalse($audit['is_ph_valid']);
        $this->assertFalse($audit['is_temp_valid']);
        $this->assertFalse($audit['is_ec_valid']);
        $this->assertStringContainsString('outside physical limits (0–14)', implode('; ', $audit['quality_notes']));
        $this->assertStringContainsString('outside physical limits (-10°C to 60°C)', implode('; ', $audit['quality_notes']));
    }
}
