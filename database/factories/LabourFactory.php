<?php

namespace Database\Factories;

use App\Models\Labour;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Labour>
 */
class LabourFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Labour::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'farm_id' => Farm::factory(),
            'name' => $this->faker->name,
            'personnel_number' => $this->faker->unique()->numerify('##########'),
            'mobile' => $this->faker->phoneNumber,
            'work_type' => $this->faker->randomElement(['shift_based', 'administrative']),
            'work_days' => $this->faker->randomElements([0, 1, 2, 3, 4, 5, 6], $this->faker->numberBetween(1, 7)),
            'work_hours' => $this->faker->numberBetween(4, 12),
            'start_work_time' => $this->faker->time('H:i:s'),
            'end_work_time' => $this->faker->time('H:i:s'),
            'hourly_wage' => $this->faker->numberBetween(50000, 200000),
            'overtime_hourly_wage' => $this->faker->numberBetween(75000, 300000),
            'user_id' => null,
            'is_working' => $this->faker->boolean,
        ];
    }
}
