<?php

namespace Database\Seeders;

use App\Models\IotDevice;
use App\Models\SensorReading;
use App\Models\SoilSample;
use App\Models\User;
use App\Services\SoilAssessmentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class SoilSampleSeeder extends Seeder
{
    public function run(): void
    {
        $device = IotDevice::where('device_uid', 'ESP32-01')->first();
        $user = User::first();
        $assessmentService = app(SoilAssessmentService::class);

        // 1. Primary demo sample: SS-2025-018
        $sample18 = SoilSample::updateOrCreate(
            ['sample_code' => 'SS-2025-018'],
            [
                'name' => 'South plot — bed 04',
                'location' => 'Demo Plot A, Laguna',
                'latitude' => 14.1699,
                'longitude' => 121.2441,
                'soil_type' => 'Loamy',
                'collected_at' => Carbon::parse('2025-05-18 10:42:00'),
                'testing_method' => 'prototype_sensor',
                'source_type' => 'demo',
                'assessment_status' => 'preliminary',
                'notes' => 'Baseline test bed collection. Surface probes placed at 10cm depth.',
                'operator_name' => 'Juan Dela Cruz',
                'device_id' => $device?->id,
                'user_id' => $user?->id,
            ]
        );

        $reading18 = SensorReading::updateOrCreate(
            [
                'soil_sample_id' => $sample18->id,
                'recorded_at' => Carbon::parse('2025-05-18 10:42:00'),
            ],
            [
                'device_id' => $device?->id,
                'ph' => 6.20,
                'moisture' => 31.00,
                'moisture_unit' => 'raw index',
                'temperature' => 28.00,
                'ec' => null, // Pending - no fake zero
                'ec_unit' => 'mS/cm',
                'is_valid' => true,
                'validity_notes' => 'pH and temperature verified; moisture uncalibrated analog index; EC awaiting sensor probe connection',
                'calibration_metadata' => [
                    'ph_probe_status' => 'calibrated',
                    'moisture_probe_status' => 'uncalibrated_analog',
                    'ec_probe_status' => 'disconnected',
                ],
                'data_source' => 'demo',
            ]
        );

        $assessmentService->evaluateSample($sample18, $reading18);

        // 2. Sample SS-2025-017 (Incomplete - missing moisture & EC)
        $sample17 = SoilSample::updateOrCreate(
            ['sample_code' => 'SS-2025-017'],
            [
                'name' => 'North field sample',
                'location' => 'North Field',
                'latitude' => 14.1750,
                'longitude' => 121.2480,
                'soil_type' => 'Loamy',
                'collected_at' => Carbon::parse('2025-05-15 14:18:00'),
                'testing_method' => 'manual_entry',
                'source_type' => 'manual',
                'assessment_status' => 'preliminary',
                'notes' => 'Field check following rain event.',
                'operator_name' => 'Juan Dela Cruz',
                'device_id' => $device?->id,
                'user_id' => $user?->id,
            ]
        );

        $reading17 = SensorReading::updateOrCreate(
            ['soil_sample_id' => $sample17->id],
            [
                'device_id' => $device?->id,
                'ph' => 6.50,
                'moisture' => null,
                'temperature' => 27.00,
                'ec' => null,
                'is_valid' => true,
                'recorded_at' => Carbon::parse('2025-05-15 14:18:00'),
                'data_source' => 'manual',
            ]
        );

        $assessmentService->evaluateSample($sample17, $reading17);

        // 3. Sample SS-2025-016 (Reviewed - all 4 calibrated parameters available!)
        $sample16 = SoilSample::updateOrCreate(
            ['sample_code' => 'SS-2025-016'],
            [
                'name' => 'Greenhouse control',
                'location' => 'Greenhouse 2',
                'latitude' => 14.1650,
                'longitude' => 121.2410,
                'soil_type' => 'Loamy',
                'collected_at' => Carbon::parse('2025-05-09 09:06:00'),
                'testing_method' => 'prototype_sensor',
                'source_type' => 'demo',
                'assessment_status' => 'reviewed',
                'notes' => 'Controlled environment soil tray test with calibrated moisture and EC probe.',
                'operator_name' => 'Juan Dela Cruz',
                'device_id' => $device?->id,
                'user_id' => $user?->id,
            ]
        );

        $reading16 = SensorReading::updateOrCreate(
            ['soil_sample_id' => $sample16->id],
            [
                'device_id' => $device?->id,
                'ph' => 6.40,
                'moisture' => 55.00,
                'moisture_unit' => '% VWC',
                'temperature' => 24.00,
                'ec' => 1.20,
                'ec_unit' => 'mS/cm',
                'is_valid' => true,
                'validity_notes' => 'Complete calibrated 4-channel telemetry record',
                'calibration_metadata' => [
                    'calibrated' => true,
                    'calibrated_at' => '2025-05-01',
                    'method' => 'two-point buffer & gravimetric',
                ],
                'recorded_at' => Carbon::parse('2025-05-09 09:06:00'),
                'data_source' => 'demo',
            ]
        );

        $assessmentService->evaluateSample($sample16, $reading16);

        // 4. Sample SS-2025-015 (High salinity and low pH stress test)
        $sample15 = SoilSample::updateOrCreate(
            ['sample_code' => 'SS-2025-015'],
            [
                'name' => 'Demo plot repeat',
                'location' => 'Demo Plot B',
                'latitude' => 14.1712,
                'longitude' => 121.2465,
                'soil_type' => 'Sandy',
                'collected_at' => Carbon::parse('2025-05-03 16:22:00'),
                'testing_method' => 'prototype_sensor',
                'source_type' => 'demo',
                'assessment_status' => 'incomplete',
                'notes' => 'Late afternoon test run with high salinity reading.',
                'operator_name' => 'Juan Dela Cruz',
                'device_id' => $device?->id,
                'user_id' => $user?->id,
            ]
        );

        $reading15 = SensorReading::updateOrCreate(
            ['soil_sample_id' => $sample15->id],
            [
                'device_id' => $device?->id,
                'ph' => 4.80,
                'moisture' => 38.00,
                'moisture_unit' => 'raw index',
                'temperature' => 32.00,
                'ec' => 3.80,
                'ec_unit' => 'mS/cm',
                'is_valid' => true,
                'recorded_at' => Carbon::parse('2025-05-03 16:22:00'),
                'data_source' => 'demo',
            ]
        );

        $assessmentService->evaluateSample($sample15, $reading15);
    }
}
