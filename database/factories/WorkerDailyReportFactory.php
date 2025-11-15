<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\User;
use App\Models\WorkerDailyReport;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WorkerDailyReport>
 */
class WorkerDailyReportFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = WorkerDailyReport::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $scheduledHours = $this->faker->randomFloat(2, 4, 8);
        $actualWorkHours = $this->faker->randomFloat(2, $scheduledHours, $scheduledHours + 2);
        $overtimeHours = max(0, $actualWorkHours - $scheduledHours);

        return [
            'employee_id' => Employee::factory(),
            'date' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'scheduled_hours' => $scheduledHours,
            'actual_work_hours' => $actualWorkHours,
            'overtime_hours' => $overtimeHours,
            'time_outside_zone' => $this->faker->numberBetween(0, 120), // minutes
            'productivity_score' => $this->faker->randomFloat(2, 70, 100),
            'status' => $this->faker->randomElement(['pending', 'approved', 'rejected']),
            'admin_added_hours' => 0,
            'admin_reduced_hours' => 0,
            'notes' => null,
            'approved_by' => null,
            'approved_at' => null,
        ];
    }
}

