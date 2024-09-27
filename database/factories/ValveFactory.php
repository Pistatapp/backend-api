<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Pump;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Valve>
 */
class ValveFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pump_id' => Pump::factory(),
            'name' => $this->faker->name,
            'location' => [
                'lat' => $this->faker->latitude,
                'lng' => $this->faker->longitude,
            ],
            'flow_rate' => $this->faker->randomFloat(2, 0, 100),
        ];
    }
}
