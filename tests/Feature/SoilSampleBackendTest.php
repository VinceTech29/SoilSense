<?php

namespace Tests\Feature;

use App\Models\CropProfile;
use App\Models\CropRequirement;
use App\Models\IotDevice;
use App\Models\SensorReading;
use App\Models\SoilAssessment;
use App\Models\SoilSample;
use App\Services\SoilAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SoilSampleBackendTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_model_relationships_function_correctly(): void
    {
        $device = IotDevice::where('device_uid', 'ESP32-01')->firstOrFail();
        $sample = SoilSample::where('sample_code', 'SS-2025-018')->firstOrFail();
        $crop = CropProfile::where('name', 'Tomato')->firstOrFail();

        // Device -> Samples & Readings
        $this->assertTrue($device->soilSamples()->exists());
        $this->assertTrue($device->sensorReadings()->exists());

        // Sample -> Device, Readings, Assessments
        $this->assertInstanceOf(IotDevice::class, $sample->device);
        $this->assertTrue($sample->sensorReadings()->exists());
        $this->assertNotNull($sample->latestReading);
        $this->assertNotNull($sample->latestAssessment);

        // Crop -> Requirements
        $this->assertInstanceOf(CropRequirement::class, $crop->requirements);
        $this->assertEquals(5.5, $crop->requirements->min_ph);
        $this->assertEquals(7.5, $crop->requirements->max_ph);

        // Assessment -> Crop Evaluations
        $assessment = $sample->latestAssessment;
        $this->assertTrue($assessment->cropEvaluations()->exists());
    }

    public function test_store_soil_sample_validation_rejects_invalid_sensor_ranges(): void
    {
        // Out of bounds pH (> 14)
        $response = $this->post('/analysis', [
            'name' => 'Invalid Test Bed',
            'collected_at' => now()->format('Y-m-d'),
            'testing_method' => 'manual_entry',
            'ph' => 15.5, // Invalid pH
        ]);

        $response->assertSessionHasErrors(['ph']);
    }

    public function test_store_soil_sample_creates_sample_reading_and_assessment(): void
    {
        $device = IotDevice::firstOrFail();

        $payload = [
            'name' => 'Automated Backend Test Bed',
            'location' => 'Field Plot C',
            'collected_at' => now()->format('Y-m-d'),
            'device_id' => $device->id,
            'testing_method' => 'manual_entry',
            'notes' => 'Automated test execution notes',
            'ph' => 6.4,
            'moisture' => 55.0,
            'moisture_unit' => '% VWC',
            'temperature' => 24.5,
            'ec' => 1.2,
            'ec_unit' => 'mS/cm',
            'is_demo' => 1,
        ];

        $response = $this->post('/analysis', $payload);

        // Assert database persistence
        $this->assertDatabaseHas('soil_samples', [
            'name' => 'Automated Backend Test Bed',
            'location' => 'Field Plot C',
            'source_type' => 'demo',
        ]);

        $sample = SoilSample::where('name', 'Automated Backend Test Bed')->firstOrFail();

        $this->assertDatabaseHas('sensor_readings', [
            'soil_sample_id' => $sample->id,
            'ph' => 6.40,
            'temperature' => 24.50,
            'ec' => 1.20,
        ]);

        $this->assertDatabaseHas('soil_assessments', [
            'soil_sample_id' => $sample->id,
            'usable_parameters_count' => 4,
        ]);

        // Redirects to report page for the newly generated sample
        $response->assertRedirect(route('report', ['id' => $sample->sample_code]));
    }

    public function test_history_filtering_by_status_and_search_term(): void
    {
        // Query by sample code
        $response = $this->get('/history?search=SS-2025-018');
        $response->assertStatus(200);
        $response->assertSee('SS-2025-018');
        $response->assertDontSee('SS-2025-017');

        // Query by status
        $responseReviewed = $this->get('/history?status=Reviewed');
        $responseReviewed->assertStatus(200);
        $responseReviewed->assertSee('SS-2025-016');
        $responseReviewed->assertDontSee('SS-2025-017');
    }

    public function test_dashboard_renders_live_database_telemetry(): void
    {
        $response = $this->get('/dashboard');
        $response->assertStatus(200);
        $response->assertSee('Soil Overview');
        $response->assertSee('ESP32-01');
        $response->assertSee('SS-2025-018');
        $response->assertSee('6.2');
    }

    public function test_crop_detail_renders_documented_citation(): void
    {
        $response = $this->get('/crops/tomato');
        $response->assertStatus(200);
        $response->assertSee('Tomato');
        $response->assertSee('FAO Irrigation and Drainage Paper 29');
    }
}
