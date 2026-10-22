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
    }
}
