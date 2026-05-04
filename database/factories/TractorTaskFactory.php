<?php

namespace Database\Factories;

use App\Models\Field;
use App\Models\TractorTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TractorTask>
 */
class TractorTaskFactory extends Factory
{
    /**
     * @return static
     */
    public function configure(): static
    {
        return $this->afterCreating(function (TractorTask $task) {
            if ($task->taskableItems()->exists()) {
                return;
            }

            $field = Field::factory()->create([
                'farm_id' => $task->tractor->farm_id,
            ]);
            $task->syncTaskableItems(Field::class, [$field->id]);
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => $this->faker->date,
            'start_time' => $this->faker->time('H:i'),
            'end_time' => $this->faker->time('H:i'),
            'tractor_id' => \App\Models\Tractor::factory(),
            'operation_id' => \App\Models\Operation::factory(),
            'created_by' => \App\Models\User::factory(),
            'status' => 'not_started'
        ];
    }
}
