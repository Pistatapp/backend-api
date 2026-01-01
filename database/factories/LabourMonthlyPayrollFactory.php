<?php

namespace Database\Factories;

use App\Models\Labour;
use App\Models\LabourMonthlyPayroll;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LabourMonthlyPayroll>
 */
class LabourMonthlyPayrollFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = LabourMonthlyPayroll::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Use random month/year to avoid unique constraint violations
        $month = $this->faker->numberBetween(1, 12);
        $year = $this->faker->numberBetween(2020, 2030);
        $totalWorkHours = $this->faker->randomFloat(2, 140, 200);
        $totalRequiredHours = $this->faker->randomFloat(2, 120, 180);
        $totalOvertimeHours = max(0, $totalWorkHours - $totalRequiredHours);

        return [
            'labour_id' => Labour::factory(),
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

