<?php

namespace Tests\Feature;

use App\Models\CropProfile;
use App\Models\IotDevice;
use App\Models\SensorReading;
use App\Models\SoilSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_mobile_dashboard_returns_structured_json(): void
    {
        $response = $this->getJson('/api/v1/mobile/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'measurements' => [
                        'ph' => ['name', 'value', 'unit', 'badge', 'tone'],
                        'moisture' => ['name', 'value', 'unit', 'badge', 'tone'],
                        'temperature' => ['name', 'value', 'unit', 'badge', 'tone'],
                        'ec' => ['name', 'value', 'unit', 'badge', 'tone'],
                    ],
                    'device',
                    'crops',
                    'recent_samples',
                ],
            ]);

        $this->assertTrue($response->json('success'));
    }

    public function test_mobile_telemetry_latest_returns_sensor_data(): void
    {
        $response = $this->getJson('/api/v1/mobile/telemetry/latest');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'ph',
                    'moisture',
                    'temperature',
                    'ec',
                    'recorded_at',
                ],
            ]);
    }

    public function test_mobile_telemetry_history_returns_series(): void
    {
        $response = $this->getJson('/api/v1/mobile/telemetry/history?limit=10');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'count',
                'data' => [
                    '*' => [
                        'id',
                        'timestamp',
                        'time_label',
                        'ph',
                        'moisture',
                        'temperature',
                        'ec',
                    ],
                ],
            ]);
    }

    public function test_mobile_crops_catalog_returns_list_with_requirements(): void
    {
        $response = $this->getJson('/api/v1/mobile/crops');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'count',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'scientific_name',
                        'requirements' => [
                            'min_ph',
                            'max_ph',
                        ],
                    ],
                ],
            ]);
    }

    public function test_mobile_crop_detail_returns_specific_crop(): void
    {
        $crop = CropProfile::first();
        $response = $this->getJson('/api/v1/mobile/crops/' . $crop->name);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => $crop->name,
                ],
            ]);
    }

    public function test_mobile_crop_detail_returns_crop_by_id(): void
    {
        $crop = CropProfile::first();
        $response = $this->getJson('/api/v1/mobile/crops/' . $crop->id);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $crop->id,
                    'name' => $crop->name,
                ],
            ]);
    }

    public function test_mobile_samples_endpoint_returns_paginated_list(): void
    {
        $response = $this->getJson('/api/v1/mobile/samples');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'sample_code',
                            'name',
                        ],
                    ],
                ],
            ]);
    }

    public function test_mobile_can_record_new_soil_sample(): void
    {
        $device = IotDevice::first();

        $payload = [
            'name' => 'Mobile Field Test Alpha',
            'location' => 'Zone 4 Field, Laguna',
            'collected_at' => now()->toIso8601String(),
            'testing_method' => 'live_sensor',
            'device_id' => $device?->id,
            'ph' => 6.5,
            'moisture' => 45.0,
            'moisture_unit' => '%',
            'temperature' => 28.5,
            'ec' => 1.2,
            'ec_unit' => 'mS/cm',
            'is_demo' => false,
            'notes' => 'Collected via Flutter mobile app',
        ];

        $response = $this->postJson('/api/v1/mobile/samples', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonPath('data.name', 'Mobile Field Test Alpha');

        $this->assertDatabaseHas('soil_samples', [
            'name' => 'Mobile Field Test Alpha',
        ]);

        $this->assertDatabaseHas('sensor_readings', [
            'ph' => 6.5,
            'moisture' => 45.0,
        ]);

        $sample = SoilSample::where('name', 'Mobile Field Test Alpha')->firstOrFail();
        $this->assertDatabaseHas('assessment_crop_evaluations', [
            'soil_assessment_id' => $sample->latestAssessment?->id,
        ]);

        $this->assertArrayHasKey('crop_evaluations', $response->json('data'));
        $this->assertNotEmpty($response->json('data.crop_evaluations'));
    }

    public function test_mobile_can_record_new_soil_sample_without_sensor_fields(): void
    {
        $payload = [
            'name' => 'Mobile Core Sample Beta',
            'location' => 'Field Plot D',
            'collected_at' => now()->toIso8601String(),
            'testing_method' => 'manual_entry',
            'notes' => 'Physical core sample awaiting laboratory chemistry',
            'is_demo' => false,
        ];

        $response = $this->postJson('/api/v1/mobile/samples', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonPath('data.name', 'Mobile Core Sample Beta');

        $this->assertDatabaseHas('soil_samples', [
            'name' => 'Mobile Core Sample Beta',
            'assessment_status' => 'incomplete',
        ]);

        $sample = SoilSample::where('name', 'Mobile Core Sample Beta')->firstOrFail();
        $this->assertDatabaseMissing('sensor_readings', [
            'soil_sample_id' => $sample->id,
        ]);

        $this->assertDatabaseHas('soil_assessments', [
            'soil_sample_id' => $sample->id,
            'status' => 'incomplete',
        ]);

        $this->assertDatabaseHas('assessment_crop_evaluations', [
            'soil_assessment_id' => $sample->latestAssessment?->id,
        ]);
    }

    public function test_mobile_assessment_detail_returns_evaluations_structure(): void
    {
        $assessment = \App\Models\SoilAssessment::firstOrFail();

        $response = $this->getJson('/api/v1/mobile/assessments/' . $assessment->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'soil_sample_id',
                    'status',
                    'usable_parameters_count',
                    'sample' => [
                        'id',
                        'sample_code',
                    ],
                    'crop_evaluations' => [
                        '*' => [
                            'crop_name',
                            'suitability_tier',
                            'match_score',
                            'limiting_factors',
                        ],
                    ],
                ],
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertNotEmpty($response->json('data.crop_evaluations'));
    }

    public function test_mobile_assessment_detail_returns_404_for_missing_id(): void
    {
        $response = $this->getJson('/api/v1/mobile/assessments/999999');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_mobile_reports_endpoint_returns_paginated_list(): void
    {
        $response = $this->getJson('/api/v1/mobile/reports');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'sample_code',
                            'name',
                        ],
                    ],
                ],
            ]);
    }

    public function test_mobile_report_detail_returns_report_content(): void
    {
        $sample = SoilSample::firstOrFail();

        $response = $this->getJson('/api/v1/mobile/reports/' . $sample->sample_code);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'sample' => ['id', 'sample_code', 'name'],
                    'reading',
                    'assessment',
                    'crop_evaluations',
                    'recommendations' => ['summary'],
                    'measurements',
                ],
            ]);
    }

    public function test_mobile_report_detail_returns_404_for_missing_id(): void
    {
        $response = $this->getJson('/api/v1/mobile/reports/NONEXISTENT-REPORT');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_mobile_devices_endpoint_returns_list_with_latest_reading(): void
    {
        $response = $this->getJson('/api/v1/mobile/devices');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'count',
                'data' => [
                    '*' => [
                        'id',
                        'device_uid',
                        'name',
                        'status',
                        'location',
                        'is_demo',
                        'last_seen_at',
                        'latest_reading',
                    ],
                ],
            ]);

        // Honest data: verify battery_level is not exposed
        $deviceData = $response->json('data.0');
        $this->assertArrayNotHasKey('battery_level', $deviceData);
    }

    public function test_mobile_device_detail_returns_device_and_latest_reading(): void
    {
        $device = IotDevice::firstOrFail();

        $response = $this->getJson('/api/v1/mobile/devices/' . $device->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'device_uid',
                    'name',
                    'status',
                    'location',
                    'is_demo',
                    'last_seen_at',
                    'latest_reading',
                ],
            ])
            ->assertJsonPath('data.id', $device->id);

        $this->assertArrayNotHasKey('battery_level', $response->json('data'));
    }

    public function test_mobile_device_detail_returns_404_for_missing_id(): void
    {
        $response = $this->getJson('/api/v1/mobile/devices/999999');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_mobile_dashboard_has_no_hardcoded_device_when_devices_table_is_empty(): void
    {
        SensorReading::query()->update(['device_id' => null]);
        SoilSample::query()->update(['device_id' => null]);
        IotDevice::query()->delete();

        $response = $this->getJson('/api/v1/mobile/dashboard');

        $response->assertStatus(200);
        $this->assertNull($response->json('data.device'));
    }
}
