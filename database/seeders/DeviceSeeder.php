<?php

namespace Database\Seeders;

use App\Models\IotDevice;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DeviceSeeder extends Seeder
{
    public function run(): void
    {
        $devices = [
            [
                'device_uid' => 'ESP32-01',
                'name' => 'Primary soil station',
                'status' => 'online',
                'location' => 'Demo Plot A, Laguna',
                'is_demo' => true,
                'api_token_hash' => hash('sha256', 'demo-esp32-token-2025'),
                'last_seen_at' => Carbon::now()->subMinutes(2),
            ],
            [
                'device_uid' => 'ESP32-02',
                'name' => 'Field Station 02',
                'status' => 'offline',
                'location' => 'North Field',
                'is_demo' => true,
                'api_token_hash' => hash('sha256', 'demo-esp32-token-02'),
                'last_seen_at' => Carbon::now()->subDays(3),
            ],
        ];

        foreach ($devices as $dev) {
            IotDevice::updateOrCreate(
                ['device_uid' => $dev['device_uid']],
                $dev
            );
        }
    }
}
