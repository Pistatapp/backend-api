<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Labour>
 */
class LabourFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => $this->faker->randomElement(['Full-time', 'Part-time']),
            'fname' => $this->faker->firstName,
            'lname' => $this->faker->lastName,
            'national_id' => $this->faker->unique()->randomNumber(9),
            'mobile' => $this->faker->phoneNumber,
            'position' => $this->faker->jobTitle,
            'project_start_date' => $this->faker->dateTimeBetween('-1 year', 'now'),
            'project_end_date' => $this->faker->dateTimeBetween('now', '+1 year'),
            'work_type' => $this->faker->randomElement(['Indoor', 'Outdoor']),
            'work_days' => $this->faker->numberBetween(1, 7),
            'work_hours' => $this->faker->numberBetween(1, 12),
            'start_work_time' => $this->faker->time('H:i:s'),
            'end_work_time' => $this->faker->time('H:i:s'),
            'salary' => $this->faker->numberBetween(1000, 5000),
            'daily_salary' => $this->faker->numberBetween(100, 500),
            'monthly_salary' => $this->faker->numberBetween(1000, 5000),
        ];
    }
}
