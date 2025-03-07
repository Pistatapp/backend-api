<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Farm>
 */
class FarmFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->word,
            'coordinates' => [
                $this->faker->latitude . ',' . $this->faker->longitude,
                $this->faker->latitude . ',' . $this->faker->longitude,
                $this->faker->latitude . ',' . $this->faker->longitude,
                $this->faker->latitude . ',' . $this->faker->longitude
            ],
            'crop_id' => \App\Models\Crop::factory(),
            'center' => $this->faker->latitude . ',' . $this->faker->longitude,
            'zoom' => $this->faker->randomFloat(2, 1, 20),
            'area' => $this->faker->randomFloat(2, 1, 100),
        ];
    }
}
