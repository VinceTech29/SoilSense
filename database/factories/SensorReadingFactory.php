<?php

namespace Database\Factories;

use App\Models\SensorReading;
use App\Models\SoilSample;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SensorReading>
 */
class SensorReadingFactory extends Factory
{
    protected $model = SensorReading::class;

    public function definition(): array
    {
        return [
            'soil_sample_id' => SoilSample::factory(),
            'device_id' => null,
            'ph' => fake()->randomFloat(2, 5.2, 7.5),
            'moisture' => fake()->randomFloat(2, 35.0, 70.0),
            'moisture_unit' => '% VWC',
            'temperature' => fake()->randomFloat(2, 20.0, 32.0),
            'ec' => fake()->randomFloat(2, 0.4, 2.2),
            'ec_unit' => 'mS/cm',
            'nitrogen' => fake()->randomFloat(1, 20.0, 150.0),
            'phosphorus' => fake()->randomFloat(1, 10.0, 90.0),
            'potassium' => fake()->randomFloat(1, 15.0, 120.0),
            'npk_source' => 'demo',
            'is_valid' => true,
            'validity_notes' => null,
            'calibration_metadata' => ['calibrated' => true],
            'recorded_at' => now(),
            'data_source' => 'demo',
        ];
    }
}
