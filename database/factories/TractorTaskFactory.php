<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TractorTask>
 */
class TractorTaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => $this->faker->date,
            'start_time' => $this->faker->dateTime,
            'end_time' => $this->faker->dateTime,
            'tractor_id' => \App\Models\Tractor::factory(),
            'field_id' => \App\Models\Field::factory(),
            'operation_id' => \App\Models\Operation::factory(),
            'created_by' => \App\Models\User::factory(),
        ];
    }
}
