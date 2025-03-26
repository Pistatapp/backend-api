<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TractorReport>
 */
class TractorReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tractor_id' => \App\Models\Tractor::factory(),
            'operation_id' => \App\Models\Operation::factory(),
            'field_id' => \App\Models\Field::factory(),
            'date' => $this->faker->date(),
            'start_time' => $this->faker->dateTime(),
            'end_time' => $this->faker->dateTime(),
            'description' => $this->faker->text(200),
            'created_by' => \App\Models\User::factory(),
        ];
    }
}
