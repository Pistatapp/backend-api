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
            'location' => $this->faker->latitude . ',' . $this->faker->longitude,
            'flow_rate' => $this->faker->randomFloat(2, 0, 100),
        ];

    }

    /**
     * Indicate that the model's is_open is false.
     *
     * @return static
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_open' => false,
        ]);
    }
}
