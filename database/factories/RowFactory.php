<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Row>
 */
class RowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'coordinates' => json_encode([
                $this->faker->latitude(),
                $this->faker->longitude(),
            ]),
            'field_id' => \App\Models\Field::factory(),
        ];
    }
}
