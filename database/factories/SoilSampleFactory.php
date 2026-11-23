<?php

namespace Database\Factories;

use App\Models\SoilSample;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SoilSample>
 */
class SoilSampleFactory extends Factory
{
    protected $model = SoilSample::class;

    public function definition(): array
    {
        return [
            'sample_code' => 'SS-' . fake()->unique()->numerify('2026-####'),
            'name' => fake()->words(3, true),
            'location' => fake()->city() . ', Laguna',
            'latitude' => fake()->randomFloat(7, 14.0, 14.3),
            'longitude' => fake()->randomFloat(7, 121.1, 121.4),
            'soil_type' => fake()->randomElement(['Sandy', 'Silt', 'Loamy', 'Clay']),
            'collected_at' => now(),
            'testing_method' => 'prototype_sensor',
            'source_type' => 'demo',
            'assessment_status' => 'preliminary',
            'notes' => fake()->sentence(),
            'operator_name' => 'Juan Dela Cruz',
            'device_id' => null,
            'user_id' => null,
        ];
    }
}
