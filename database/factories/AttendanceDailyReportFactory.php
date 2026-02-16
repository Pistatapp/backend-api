<?php

namespace Database\Factories;

use App\Models\AttendanceDailyReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceDailyReportFactory extends Factory
{
    protected $model = AttendanceDailyReport::class;

    public function definition(): array
    {
        $scheduledHours = $this->faker->randomFloat(2, 4, 8);
        $actualWorkHours = $this->faker->randomFloat(2, $scheduledHours, $scheduledHours + 2);
        $overtimeHours = max(0, $actualWorkHours - $scheduledHours);

        return [
            'user_id' => User::factory(),
            'date' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'scheduled_hours' => $scheduledHours,
            'actual_work_hours' => $actualWorkHours,
            'overtime_hours' => $overtimeHours,
            'time_outside_zone' => $this->faker->numberBetween(0, 120),
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
