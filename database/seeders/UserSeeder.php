<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'juan@example.edu.ph'],
            [
                'name' => 'Juan Dela Cruz',
                'role' => 'Agricultural Researcher',
                'organization' => 'University Research Farm',
                'region' => 'CALABARZON',
                'phone' => '+63 917 123 4567',
                'password' => Hash::make('password123'),
                'email_verified_at' => now(),
                'preferences' => [
                    'temp_unit' => 'C',
                    'moisture_unit' => '% VWC',
                    'ec_unit' => 'mS/cm',
                    'ph_precision' => '1',
                    'default_location' => 'Demo Farm, Laguna',
                    'default_testing_method' => 'Sensor Probe + DA Lab',
                    'sample_prefix' => 'SS',
                    'auto_timestamp' => true,
                    'recommendation_tier' => 'balanced',
                    'missing_data_policy' => 'flag_uncalibrated',
                    'active_device_id' => 'ESP32-01',
                    'sync_interval_seconds' => 60,
                ],
            ]
        );
    }
}
