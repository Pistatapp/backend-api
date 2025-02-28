<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Field>
 */
class FieldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'farm_id' => \App\Models\Farm::factory(),
            'name' => $this->faker->word,
            'coordinates' => [
                implode(',', [
                    $this->faker->latitude,
                    $this->faker->longitude,
                ]),
                implode(',', [
                    $this->faker->latitude,
                    $this->faker->longitude,
                ]),
                implode(',', [
                    $this->faker->latitude,
                    $this->faker->longitude,
                ]),
                implode(',', [
                    $this->faker->latitude,
                    $this->faker->longitude,
                ])
            ],
            'center' => $this->faker->latitude . ',' . $this->faker->longitude,
            'area' => $this->faker->randomFloat(2, 1, 100),
            'crop_type_id' => \App\Models\CropType::factory(),
        ];
    }
}
