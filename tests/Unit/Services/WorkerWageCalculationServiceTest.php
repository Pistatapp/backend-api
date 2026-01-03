<?php

namespace Tests\Unit\Services;

use App\Models\Labour;
use App\Models\Farm;
use App\Models\WorkShift;
use App\Models\LabourShiftSchedule;
use App\Services\LabourWageCalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerWageCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private LabourWageCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LabourWageCalculationService();
    }

    /**
     * Test get required hours for shift-based worker.
     */
    public function test_get_required_hours_for_shift_based_worker(): void
    {
        $farm = Farm::factory()->create();
        $labour = Labour::factory()->create([
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

        LabourShiftSchedule::factory()->create([
            'labour_id' => $labour->id,
            'shift_id' => $shift1->id,
            'scheduled_date' => $date->toDateString(),
            'status' => 'completed',
        ]);

        LabourShiftSchedule::factory()->create([
            'labour_id' => $labour->id,
            'shift_id' => $shift2->id,
            'scheduled_date' => $date->toDateString(),
            'status' => 'completed',
        ]);

        $requiredHours = $this->service->getRequiredHours($labour, $date);

        $this->assertEquals(8.0, $requiredHours);
    }

    /**
     * Test get required hours for administrative worker on work day.
     */
    public function test_get_required_hours_for_administrative_worker_on_work_day(): void
    {
        $labour = Labour::factory()->create([
            'work_type' => 'administrative',
            'work_days' => [0, 1, 2, 3, 4], // Sunday to Thursday
            'work_hours' => 8.0,
        ]);

        $date = Carbon::parse('2024-11-15'); // Friday (day 5) - not in work_days

        // Try a day that is NOT a work day
        $requiredHours = $this->service->getRequiredHours($labour, $date);
        $this->assertEquals(0.0, $requiredHours);

        // Try a day that IS a work day (Monday - day 1)
        $workDate = Carbon::parse('2024-11-11'); // Monday (day 1)
        $requiredHours = $this->service->getRequiredHours($labour, $workDate);
        $this->assertEquals(8.0, $requiredHours);
    }

    /**
     * Test get required hours returns 0 for administrative worker on non-work day.
     */
    public function test_get_required_hours_returns_0_for_administrative_worker_on_non_work_day(): void
    {
        $labour = Labour::factory()->create([
            'work_type' => 'administrative',
            'work_days' => [0, 1, 2, 3, 4], // Sunday to Thursday
            'work_hours' => 8.0,
        ]);

        $date = Carbon::parse('2024-11-15'); // Friday (day 5)
        $requiredHours = $this->service->getRequiredHours($labour, $date);

        $this->assertEquals(0.0, $requiredHours);
    }

    /**
     * Test get required hours for shift-based worker with no completed shifts.
     */
    public function test_get_required_hours_for_shift_based_worker_with_no_completed_shifts(): void
    {
        $labour = Labour::factory()->create([
            'work_type' => 'shift_based',
        ]);

        $date = Carbon::today();
        $requiredHours = $this->service->getRequiredHours($labour, $date);

        $this->assertEquals(0.0, $requiredHours);
    }

    /**
     * Test calculate base wage.
     */
    public function test_calculate_base_wage(): void
    {
        $labour = Labour::factory()->create([
            'hourly_wage' => 100000, // 100,000 per hour
        ]);

        $baseWage = $this->service->calculateBaseWage($labour, 8.0);

        $this->assertEquals(800000, $baseWage); // 8 hours * 100000
    }

    /**
     * Test calculate base wage with zero hourly wage.
     */
    public function test_calculate_base_wage_with_zero_hourly_wage(): void
    {
        $labour = Labour::factory()->create([
            'hourly_wage' => 0,
        ]);

        $baseWage = $this->service->calculateBaseWage($labour, 8.0);

        $this->assertEquals(0, $baseWage);
    }

    /**
     * Test calculate overtime wage.
     */
    public function test_calculate_overtime_wage(): void
    {
        $labour = Labour::factory()->create([
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 150000,
        ]);

        $overtimeWage = $this->service->calculateOvertimeWage($labour, 2.0);

        $this->assertEquals(300000, $overtimeWage); // 2 hours * 150000
    }

    /**
     * Test calculate overtime wage falls back to hourly wage if overtime wage is 0.
     */
    public function test_calculate_overtime_wage_falls_back_to_hourly_wage(): void
    {
        $labour = Labour::factory()->create([
            'hourly_wage' => 100000,
            'overtime_hourly_wage' => 0, // Set to 0 to test fallback behavior
        ]);

        // Manually set the attribute to null to test the fallback logic
        // (since DB column is NOT NULL, we test the service logic by setting attribute)
        $labour->setAttribute('overtime_hourly_wage', null);
        
        $overtimeWage = $this->service->calculateOvertimeWage($labour, 2.0);

        $this->assertEquals(200000, $overtimeWage); // 2 hours * 100000 (falls back to hourly_wage)
    }

    /**
     * Test get required hours only counts completed shifts.
     */
    public function test_get_required_hours_only_counts_completed_shifts(): void
    {
        $farm = Farm::factory()->create();
        $labour = Labour::factory()->create([
            'farm_id' => $farm->id,
            'work_type' => 'shift_based',
        ]);

        $shift = WorkShift::factory()->create([
            'farm_id' => $farm->id,
            'work_hours' => 8.0,
        ]);

        $date = Carbon::today();

        // Create scheduled shift (should not be counted)
        LabourShiftSchedule::factory()->create([
            'labour_id' => $labour->id,
            'shift_id' => $shift->id,
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        // Create completed shift with different shift (to avoid unique constraint)
        $shift2 = WorkShift::factory()->create([
            'farm_id' => $farm->id,
            'work_hours' => 8.0,
        ]);
        LabourShiftSchedule::factory()->create([
            'labour_id' => $labour->id,
            'shift_id' => $shift2->id,
            'scheduled_date' => $date,
            'status' => 'completed',
        ]);

        $requiredHours = $this->service->getRequiredHours($labour, $date);

        // Should only count completed shift
        $this->assertEquals(8.0, $requiredHours);
    }
}

