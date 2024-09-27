<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Pump>
 */
class PumpFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'farm_id' => 1,
            'name' => $this->faker->name,
            'serial_number' => $this->faker->uuid,
            'model' => $this->faker->word,
            'manufacturer' => $this->faker->word,
            'horsepower' => $this->faker->randomFloat(2, 0, 100),
            'phase' => $this->faker->randomElement(['1', '3']),
            'voltage' => $this->faker->randomElement(['110', '220', '380']),
            'ampere' => $this->faker->randomFloat(2, 0, 100),
            'rpm' => $this->faker->randomFloat(2, 0, 100),
            'pipe_size' => $this->faker->randomFloat(2, 0, 100),
            'debi' => $this->faker->randomFloat(2, 0, 100),
            'is_active' => $this->faker->boolean,
            'is_healthy' => $this->faker->boolean,
            'location' => [
                'latitude' => $this->faker->latitude,
                'longitude' => $this->faker->longitude,
            ],
            'tempurature' => $this->faker->randomFloat(2, 0, 100),
        ];
    }
}
