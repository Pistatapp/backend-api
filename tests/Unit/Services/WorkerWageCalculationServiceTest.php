<?php

namespace Tests\Unit\Services;

use App\Models\Employee;
use App\Models\Farm;
use App\Models\WorkShift;
use App\Models\WorkerShiftSchedule;
use App\Services\WorkerWageCalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerWageCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private WorkerWageCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkerWageCalculationService();
    }

    /**
     * Test get required hours for shift-based worker.
     */
    public function test_get_required_hours_for_shift_based_worker(): void
    {
        $farm = Farm::factory()->create();
        $employee = Employee::factory()->create([
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
        ]);

        $shift1 = WorkShift::factory()->create([
            'farm_id' => $farm->id,
            'work_hours' => 4.0,
        ]);

        $shift2 = WorkShift::factory()->create([
            'farm_id' => $farm->id,
            'work_hours' => 4.0,
        ]);

        $date = Carbon::today();

        // Refresh shifts to ensure they're loaded correctly
        $shift1->refresh();
        $shift2->refresh();

        WorkerShiftSchedule::factory()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift1->id,
            'scheduled_date' => $date->toDateString(),
            'status' => 'completed',
        ]);

        WorkerShiftSchedule::factory()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift2->id,
            'scheduled_date' => $date->toDateString(),
            'status' => 'completed',
        ]);

        $requiredHours = $this->service->getRequiredHours($employee, $date);

        $this->assertEquals(8.0, $requiredHours);
    }

    /**
     * Test get required hours for administrative worker on work day.
     */
    public function test_get_required_hours_for_administrative_worker_on_work_day(): void
    {
        $employee = Employee::factory()->create([
            'work_type' => 'administrative',
            'work_days' => [0, 1, 2, 3, 4], // Sunday to Thursday
            'work_hours' => 8.0,
        ]);

        $date = Carbon::parse('2024-11-15'); // Friday (day 5) - not in work_days

        // Try a day that is NOT a work day
        $requiredHours = $this->service->getRequiredHours($employee, $date);
        $this->assertEquals(0.0, $requiredHours);

        // Try a day that IS a work day (Monday - day 1)
        $workDate = Carbon::parse('2024-11-11'); // Monday (day 1)
        $requiredHours = $this->service->getRequiredHours($employee, $workDate);
        $this->assertEquals(8.0, $requiredHours);
    }

    /**
     * Test get required hours returns 0 for administrative worker on non-work day.
     */
    public function test_get_required_hours_returns_0_for_administrative_worker_on_non_work_day(): void
    {
        $employee = Employee::factory()->create([
            'work_type' => 'administrative',
            'work_days' => [0, 1, 2, 3, 4], // Sunday to Thursday
            'work_hours' => 8.0,
        ]);

        $date = Carbon::parse('2024-11-15'); // Friday (day 5)
        $requiredHours = $this->service->getRequiredHours($employee, $date);

        $this->assertEquals(0.0, $requiredHours);
    }

    /**
     * Test get required hours for shift-based worker with no completed shifts.
     */
    public function test_get_required_hours_for_shift_based_worker_with_no_completed_shifts(): void
    {
        $employee = Employee::factory()->create([
            'work_type' => 'shift_based',
        ]);

        $date = Carbon::today();
        $requiredHours = $this->service->getRequiredHours($employee, $date);

        $this->assertEquals(0.0, $requiredHours);
    }

    /**
     * Test calculate base wage.
     */
    public function test_calculate_base_wage(): void
    {
        $employee = Employee::factory()->create([
            'hourly_wage' => 100000, // 100,000 per hour
        ]);

        $baseWage = $this->service->calculateBaseWage($employee, 8.0);

        $this->assertEquals(800000, $baseWage); // 8 hours * 100000
    }

    /**
     * Test calculate base wage with zero hourly wage.
     */
    public function test_calculate_base_wage_with_zero_hourly_wage(): void
    {
        $employee = Employee::factory()->create([
            'hourly_wage' => 0,
        ]);

        $baseWage = $this->service->calculateBaseWage($employee, 8.0);

        $this->assertEquals(0, $baseWage);
    }

    /**
     * Test calculate overtime wage.
     */
    public function test_calculate_overtime_wage(): void
    {
        $employee = Employee::factory()->create([
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
        ]);

        $overtimeWage = $this->service->calculateOvertimeWage($employee, 2.0);

        $this->assertEquals(300000, $overtimeWage); // 2 hours * 150000
    }

    /**
     * Test calculate overtime wage falls back to hourly wage if overtime wage not set.
     */
    public function test_calculate_overtime_wage_falls_back_to_hourly_wage(): void
    {
        $employee = Employee::factory()->create([
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => null,
        ]);

        $overtimeWage = $this->service->calculateOvertimeWage($employee, 2.0);

        $this->assertEquals(200000, $overtimeWage); // 2 hours * 100000
    }

    /**
     * Test get required hours only counts completed shifts.
     */
    public function test_get_required_hours_only_counts_completed_shifts(): void
    {
        $farm = Farm::factory()->create();
        $employee = Employee::factory()->create([
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
        ]);

        $shift = WorkShift::factory()->create([
            'farm_id' => $farm->id,
            'work_hours' => 8.0,
        ]);

        $date = Carbon::today();

        // Create scheduled shift (should not be counted)
        WorkerShiftSchedule::factory()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        // Create completed shift with different shift (to avoid unique constraint)
        $shift2 = WorkShift::factory()->create([
            'farm_id' => $farm->id,
            'work_hours' => 8.0,
        ]);
        WorkerShiftSchedule::factory()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift2->id,
            'scheduled_date' => $date,
            'status' => 'completed',
        ]);

        $requiredHours = $this->service->getRequiredHours($employee, $date);

        // Should only count completed shift
        $this->assertEquals(8.0, $requiredHours);
    }
}

