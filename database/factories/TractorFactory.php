<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tractor>
 */
class TractorFactory extends Factory
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
            'start_work_time' => $this->faker->time,
            'end_work_time' => $this->faker->time,
            'expected_daily_work_time' => $this->faker->numberBetween(1, 24),
            'expected_monthly_work_time' => $this->faker->numberBetween(1, 720),
            'expected_yearly_work_time' => $this->faker->numberBetween(1, 8640),
            'is_working' => $this->faker->boolean,
            'farm_id' => \App\Models\Farm::factory(),
        ];
    }
}
