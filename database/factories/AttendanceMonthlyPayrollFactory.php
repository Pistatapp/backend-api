<?php

namespace Database\Factories;

use App\Models\AttendanceMonthlyPayroll;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceMonthlyPayrollFactory extends Factory
{
    protected $model = AttendanceMonthlyPayroll::class;

    public function definition(): array
    {
        $month = $this->faker->numberBetween(1, 12);
        $year = $this->faker->numberBetween(2020, 2030);
        $totalWorkHours = $this->faker->randomFloat(2, 140, 200);
        $totalRequiredHours = $this->faker->randomFloat(2, 120, 180);
        $totalOvertimeHours = max(0, $totalWorkHours - $totalRequiredHours);

        return [
            'user_id' => User::factory(),
            'month' => $month,
            'year' => $year,
            'total_work_hours' => $totalWorkHours,
            'total_required_hours' => $totalRequiredHours,
            'total_overtime_hours' => $totalOvertimeHours,
            'base_wage_total' => $this->faker->numberBetween(10000000, 50000000),
            'overtime_wage_total' => $this->faker->numberBetween(1000000, 10000000),
            'additions' => $this->faker->numberBetween(0, 5000000),
            'deductions' => $this->faker->numberBetween(0, 3000000),
            'final_total' => $this->faker->numberBetween(10000000, 60000000),
            'generated_at' => Carbon::now(),
        ];
    }
}
