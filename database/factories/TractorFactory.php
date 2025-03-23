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
            'start_work_time' => '08:00',
            'end_work_time' => '16:00',
            'expected_daily_work_time' => 8, // 8 hours per day
            'expected_monthly_work_time' => 240, // ~8 hours * 30 days
            'expected_yearly_work_time' => 2920, // ~8 hours * 365 days
            'is_working' => $this->faker->boolean,
            'farm_id' => \App\Models\Farm::factory(),
        ];
    }
}
