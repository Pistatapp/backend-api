<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\WorkerAttendanceSession;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WorkerAttendanceSession>
 */
class WorkerAttendanceSessionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = WorkerAttendanceSession::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = $this->faker->dateTimeBetween('-30 days', 'now');
        $entryTime = Carbon::parse($date)->setTime(8, 0, 0);
        $exitTime = $entryTime->copy()->addHours(8);

        return [
            'employee_id' => Employee::factory(),
            'date' => $date,
            'entry_time' => $entryTime,
            'exit_time' => $exitTime,
            'total_in_zone_duration' => $this->faker->numberBetween(400, 480), // minutes
            'total_out_zone_duration' => $this->faker->numberBetween(0, 60), // minutes
            'status' => $this->faker->randomElement(['in_progress', 'completed']),
        ];
    }
}

