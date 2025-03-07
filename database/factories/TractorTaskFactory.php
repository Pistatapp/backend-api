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
            'status' => $this->faker->randomElement(['pending', 'in_progress', 'completed']),
            'tractor_id' => \App\Models\Tractor::factory(),
            'field_ids' => \App\Models\Field::factory()->count(10)->create()->pluck('id'),
            'operation_id' => \App\Models\Operation::factory(),
            'created_by' => \App\Models\User::factory(),
        ];
    }
}
