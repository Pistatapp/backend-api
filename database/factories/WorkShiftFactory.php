<?php

namespace Database\Factories;

use App\Models\Farm;
use App\Models\WorkShift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WorkShift>
 */
class WorkShiftFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = WorkShift::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startTime = $this->faker->time('H:i:s');
        $endTime = $this->faker->time('H:i:s');
        $workHours = $this->faker->randomFloat(2, 4, 12);

        return [
            'farm_id' => Farm::factory(),
            'name' => $this->faker->randomElement(['Morning Shift', 'Afternoon Shift', 'Evening Shift', 'Night Shift']),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'work_hours' => $workHours,
        ];
    }
}

