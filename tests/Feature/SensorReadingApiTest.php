<?php

namespace Tests\Feature;

use App\Models\IotDevice;
use App\Models\SensorReading;
use App\Models\SoilAssessment;
use App\Models\SoilSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SensorReadingApiTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;
    protected string $validToken = 'demo-esp32-token-2025';
    protected string $deviceUid = 'ESP32-01';
    protected string $sampleCode = 'SS-2025-018';

    /**
     * Test 1: Valid authenticated payload ingests telemetry, updates device status, and returns 201 Created.
     */
    public function test_valid_authenticated_payload_is_stored_successfully(): void
    {
        $payload = [
            'sample_id' => $this->sampleCode,
            'device_id' => $this->deviceUid,
            'ph' => 6.35,
            'moisture_percent' => 52.0,
            'temperature_c' => 26.5,
            'ec_ms_cm' => 1.45,
            'recorded_at' => Carbon::now()->toIso8601String(),
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validToken,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/readings', $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'data' => [
                'sample_id' => $this->sampleCode,
                'device_id' => $this->deviceUid,
                'device_status' => 'online',
                'measurements' => [
                    'ph' => ['value' => 6.35, 'valid' => true],
                    'moisture' => ['value' => 52.0, 'unit' => '% VWC', 'calibrated' => true],
                    'temperature' => ['value' => 26.5, 'unit' => '°C', 'valid' => true],
                    'ec' => ['value' => 1.45, 'unit' => 'mS/cm', 'valid' => true],
                ],
                'data_quality' => [
                    'usable_parameters_count' => 4,
                    'total_parameters' => 4,
                ],
                'assessment' => [
                    'triggered' => true,
                ],
            ],
        ]);

        // Database assertions
        $this->assertDatabaseHas('sensor_readings', [
            'ph' => 6.35,
            'moisture' => 52.00,
            'temperature' => 26.50,
            'ec' => 1.45,
        ]);

        // Assert device last_seen_at was updated
        $device = IotDevice::where('device_uid', $this->deviceUid)->first();
        $this->assertNotNull($device->last_seen_at);
        $this->assertEquals('online', $device->status);
    }

    /**
     * Test 2: Missing optional measurements (null values) are preserved without substituting zero.
     */
    public function test_missing_optional_measurements_are_preserved_without_fake_zeros(): void
    {
        $payload = [
            'sample_id' => $this->sampleCode,
            'device_id' => $this->deviceUid,
            'ph' => 6.2,
            'moisture_percent' => null, // Moisture not available
            'temperature_c' => 27.0,
            'ec_ms_cm' => null, // EC not available
        ];

        $response = $this->withHeaders([
            'X-Device-Token' => $this->validToken,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/readings', $payload);

        $response->assertStatus(201);

        $reading = SensorReading::latest('id')->first();
        $this->assertNull($reading->moisture);
        $this->assertNull($reading->ec);
        $this->assertNotSame(0, $reading->moisture);
        $this->assertNotSame(0.0, $reading->moisture);
        $this->assertNotSame(0, $reading->ec);
        $this->assertNotSame(0.0, $reading->ec);
    }

    /**
     * Test 3: Invalid types and out-of-range sensor measurements are rejected with 422 Unprocessable Entity.
     */
    public function test_invalid_types_and_ranges_are_rejected(): void
    {
        // Case A: Out of range pH (> 14.0)
        $responsePh = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validToken,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/readings', [
            'sample_id' => $this->sampleCode,
            'device_id' => $this->deviceUid,
            'ph' => 15.5, // Invalid pH
        ]);

        $responsePh->assertStatus(422);
        $responsePh->assertJson([
            'success' => false,
            'code' => 'VALIDATION_FAILED',
        ]);
        $responsePh->assertJsonValidationErrors(['ph']);

        // Case B: Non-numeric temperature
        $responseTemp = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validToken,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/readings', [
            'sample_id' => $this->sampleCode,
            'device_id' => $this->deviceUid,
            'temperature_c' => 'not-a-number',
        ]);

        $responseTemp->assertStatus(422);
        $responseTemp->assertJsonValidationErrors(['temperature_c']);
    }

    /**
     * Test 4: Unauthorized request (missing or invalid token) returns 401 Unauthorized.
     */
    public function test_unauthorized_devices_are_rejected(): void
    {
        // Missing token
        $responseNoToken = $this->postJson('/api/v1/readings', [
            'sample_id' => $this->sampleCode,
            'device_id' => $this->deviceUid,
        ]);

        $responseNoToken->assertStatus(401);
        $responseNoToken->assertJson([
            'success' => false,
            'code' => 'AUTH_TOKEN_MISSING',
        ]);

        // Invalid token
        $responseBadToken = $this->withHeaders([
            'Authorization' => 'Bearer invalid-hacker-token',
        ])->postJson('/api/v1/readings', [
            'sample_id' => $this->sampleCode,
            'device_id' => $this->deviceUid,
        ]);

        $responseBadToken->assertStatus(401);
        $responseBadToken->assertJson([
            'success' => false,
            'code' => 'AUTH_TOKEN_INVALID',
        ]);
    }

    /**
     * Test 5: Unknown sample ID returns 404 Not Found with consistent JSON structure.
     */
    public function test_unknown_sample_id_returns_404(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validToken,
        ])->postJson('/api/v1/readings', [
            'sample_id' => 'NON-EXISTENT-SAMPLE-999',
            'device_id' => $this->deviceUid,
            'ph' => 6.5,
        ]);

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'code' => 'SAMPLE_NOT_FOUND',
        ]);
    }

    /**
     * Test 6: Device ID mismatch (token belongs to ESP32-01, but payload specifies ESP32-02) returns 403 Forbidden.
     */
    public function test_device_id_mismatch_returns_403_forbidden(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validToken,
        ])->postJson('/api/v1/readings', [
            'sample_id' => $this->sampleCode,
            'device_id' => 'ESP32-02', // Mismatched UID
            'ph' => 6.5,
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'code' => 'DEVICE_IDENTITY_MISMATCH',
        ]);
    }

    /**
     * Test 7: Duplicate submissions within 10 seconds are rejected with 409 Conflict.
     */
    public function test_repeated_submissions_are_protected_by_idempotency_check(): void
    {
        $payload = [
            'sample_id' => $this->sampleCode,
            'device_id' => $this->deviceUid,
            'ph' => 6.4,
            'moisture_percent' => 50.0,
            'temperature_c' => 25.0,
            'ec_ms_cm' => 1.2,
        ];

        // First submission succeeds
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validToken,
        ])->postJson('/api/v1/readings', $payload);

        $response1->assertStatus(201);

        // Immediate duplicate submission gets rejected
        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validToken,
        ])->postJson('/api/v1/readings', $payload);

        $response2->assertStatus(409);
        $response2->assertJson([
            'success' => false,
            'code' => 'DUPLICATE_SUBMISSION',
        ]);
    }

    /**
     * Test 8: Assessment triggering occurs when sufficient usable parameters are present (>= 2).
     */
    public function test_assessment_is_triggered_when_usable_parameters_are_sufficient(): void
    {
        $payloadSufficient = [
            'sample_id' => $this->sampleCode,
            'device_id' => $this->deviceUid,
            'ph' => 6.5,
            'moisture_percent' => 55.0,
            'temperature_c' => 24.0,
            'ec_ms_cm' => 1.3,
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validToken,
        ])->postJson('/api/v1/readings', $payloadSufficient);

        $response->assertStatus(201);
        $response->assertJsonPath('data.assessment.triggered', true);
        $this->assertNotNull($response->json('data.assessment.summary'));

        // Check SoilAssessment was updated in database
        $sample = SoilSample::where('sample_code', $this->sampleCode)->first();
        $this->assertNotNull($sample->latestAssessment);
        $this->assertEquals(4, $sample->latestAssessment->usable_parameters_count);
    }

    /**
     * Test 9: Assessment is NOT triggered when fewer than 2 usable parameters are provided.
     */
    public function test_assessment_is_not_triggered_with_insufficient_parameters(): void
    {
        // Create an unassessed fresh sample
        $freshSample = SoilSample::create([
            'sample_code' => 'SS-TEST-999',
            'name' => 'Sparse test plot',
            'collected_at' => Carbon::now(),
            'testing_method' => 'prototype_sensor',
            'source_type' => 'demo',
            'assessment_status' => 'incomplete',
        ]);

        $sparsePayload = [
            'sample_id' => 'SS-TEST-999',
            'device_id' => $this->deviceUid,
            'ph' => 6.2, // Only 1 usable parameter
            'moisture_percent' => null,
            'temperature_c' => null,
            'ec_ms_cm' => null,
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validToken,
        ])->postJson('/api/v1/readings', $sparsePayload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.assessment.triggered', false);
        $response->assertJsonPath('data.assessment.status', 'pending_sufficient_telemetry');
    }

    /**
     * Test 10: Device credentials and token hashes are never exposed in responses or logs.
     */
    public function test_device_credentials_are_never_exposed_in_api_responses(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validToken,
        ])->postJson('/api/v1/readings', [
            'sample_id' => $this->sampleCode,
            'device_id' => $this->deviceUid,
            'ph' => 6.5,
            'temperature_c' => 25.0,
        ]);

        $response->assertStatus(201);
        $content = $response->getContent();

        $this->assertStringNotContainsString($this->validToken, $content);
        $this->assertStringNotContainsString('api_token_hash', $content);
        $this->assertStringNotContainsString(hash('sha256', $this->validToken), $content);
    }
}
