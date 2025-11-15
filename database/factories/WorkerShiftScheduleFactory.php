<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\WorkShift;
use App\Models\WorkerShiftSchedule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WorkerShiftSchedule>
 */
class WorkerShiftScheduleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = WorkerShiftSchedule::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'shift_id' => WorkShift::factory(),
            'scheduled_date' => $this->faker->dateTimeBetween('now', '+30 days'),
            'status' => $this->faker->randomElement(['scheduled', 'completed', 'missed', 'cancelled']),
        ];
    }
}

