<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Plot;

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
            'plot_id' => Plot::factory(),
            'name' => $this->faker->name,
            'location' => [
                'lat' => $this->faker->latitude,
                'lng' => $this->faker->longitude
            ],
            'is_open' => false,
            'irrigation_area' => $this->faker->randomFloat(2, 0.5, 10.0),
            'dripper_count' => $this->faker->numberBetween(100, 1000),
            'dripper_flow_rate' => $this->faker->randomFloat(2, 1, 10),
        ];
    }

    /**
     * Indicate that the valve is open.
     *
     * @return static
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_open' => true,
        ]);
    }
}
