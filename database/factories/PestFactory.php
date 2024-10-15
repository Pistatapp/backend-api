<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Pest>
 */
class PestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name,
            'scientific_name' => $this->faker->name,
            'description' => $this->faker->text,
            'damage' => $this->faker->text,
            'management' => $this->faker->text,
            'standard_day_degree' => $this->faker->randomFloat(2, 0, 100),
        ];
    }
}
