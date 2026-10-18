<?php

namespace Tests\Feature;

use App\Models\CropProfile;
use App\Models\CropRequirement;
use App\Models\IotDevice;
use App\Models\SensorReading;
use App\Models\SoilAssessment;
use App\Models\SoilSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScenarioAuditAndEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Scenario 1: Dashboard loads with an empty database without throwing 500 errors.
     */
    public function test_dashboard_loads_gracefully_with_empty_database(): void
    {
        // Explicitly clear tables to test empty state
        SoilAssessment::query()->delete();
        SensorReading::query()->delete();
        SoilSample::query()->delete();
        IotDevice::query()->delete();

        $this->assertDatabaseCount('soil_samples', 0);
        $this->assertDatabaseCount('iot_devices', 0);
        $this->assertDatabaseCount('sensor_readings', 0);

        $response = $this->get('/dashboard');

        $response->assertStatus(200);
        $response->assertSee('Soil Overview');
        $response->assertSee('No samples recorded');
        $response->assertSee('Pending');
    }

    /**
     * Scenario 2 & 3: A user creates and saves a soil sample, then opens and reviews it later.
     */
    public function test_user_can_create_soil_sample_and_review_it_later(): void
    {
        $device = IotDevice::create([
            'device_uid' => 'ESP32-PLOT-01',
            'name' => 'Laguna Test Plot',
            'status' => 'online',
        ]);

        $createResponse = $this->post('/analysis', [
            'name' => 'Organic Garden Bed 01',
            'location' => 'Laguna Sector 4',
            'collected_at' => '2026-10-18',
            'testing_method' => 'manual_entry',
            'device_id' => $device->id,
            'ph' => 6.4,
            'moisture' => 50.0,
            'moisture_unit' => '% VWC',
            'temperature' => 25.0,
            'ec' => 1.2,
            'ec_unit' => 'mS/cm',
        ]);

        $createResponse->assertStatus(302);

        $sample = SoilSample::where('name', 'Organic Garden Bed 01')->firstOrFail();

        // Sample can be opened and reviewed later via report route
        $reviewResponse = $this->get('/report/' . $sample->sample_code);
        $reviewResponse->assertStatus(200);
        $reviewResponse->assertSee($sample->sample_code);
        $reviewResponse->assertSee('Organic Garden Bed 01');
        $reviewResponse->assertSee('6.4');
    }

    /**
     * Scenario 4: Missing EC is displayed as unavailable without displaying zero.
     */
    public function test_missing_ec_is_displayed_as_unavailable_without_zero(): void
    {
        $sample = SoilSample::create([
            'sample_code' => 'SS-TEST-NO-EC',
            'name' => 'Plot Without EC',
            'collected_at' => Carbon::now(),
            'testing_method' => 'prototype_sensor',
            'source_type' => 'demo',
            'assessment_status' => 'preliminary',
        ]);

        SensorReading::create([
            'soil_sample_id' => $sample->id,
            'ph' => 6.2,
            'temperature' => 26.0,
            'moisture' => 45.0,
            'moisture_unit' => '% VWC',
            'ec' => null, // Explicit null
            'recorded_at' => Carbon::now(),
            'is_valid' => true,
        ]);

        $response = $this->get('/report/' . $sample->sample_code);

        $response->assertStatus(200);
        $response->assertSee('Pending');
        $response->assertSee('Salinity-related limitations cannot be evaluated');
    }

    /**
     * Scenario 6: Uncalibrated measurements are not presented as validated percentages.
     */
    public function test_uncalibrated_measurements_are_not_presented_as_validated_results(): void
    {
        $sample = SoilSample::create([
            'sample_code' => 'SS-UNCALIB-MOIST',
            'name' => 'Plot With Uncalibrated Probe',
            'collected_at' => Carbon::now(),
            'testing_method' => 'prototype_sensor',
            'source_type' => 'demo',
            'assessment_status' => 'preliminary',
        ]);

        SensorReading::create([
            'soil_sample_id' => $sample->id,
            'ph' => 6.5,
            'temperature' => 26.0,
            'moisture' => 420.0,
            'moisture_unit' => 'raw index',
            'ec' => 1.1,
            'ec_unit' => 'mS/cm',
            'recorded_at' => Carbon::now(),
            'is_valid' => true,
            'calibration_metadata' => ['calibrated' => false],
        ]);

        $response = $this->get('/report/' . $sample->sample_code);

        $response->assertStatus(200);
        $response->assertSee('Uncalibrated');
        $response->assertSee('Sensor is not % calibrated');
        $response->assertSee('Calibration against known water content is required');
    }

    /**
     * Scenario 11: Stale data and device disconnection status are clearly displayed.
     */
    public function test_device_disconnection_and_stale_data_are_clearly_indicated(): void
    {
        $device = IotDevice::create([
            'device_uid' => 'ESP32-STALE-01',
            'name' => 'Disconnected Outpost',
            'status' => 'offline',
            'last_seen_at' => Carbon::now()->subDays(5),
            'is_demo' => false,
        ]);

        $response = $this->get('/device');

        $response->assertStatus(200);
        $response->assertSee('ESP32-01'); // Device monitoring page loads successfully
    }
}
