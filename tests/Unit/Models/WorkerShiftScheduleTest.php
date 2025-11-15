<?php

namespace Tests\Unit\Models;

use App\Models\Employee;
use App\Models\WorkShift;
use App\Models\WorkerShiftSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class WorkerShiftScheduleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that shift schedule belongs to employee.
     */
    public function test_shift_schedule_belongs_to_employee(): void
    {
        $employee = Employee::factory()->create();
        $schedule = WorkerShiftSchedule::factory()->create(['employee_id' => $employee->id]);

        $this->assertInstanceOf(Employee::class, $schedule->employee);
        $this->assertEquals($employee->id, $schedule->employee->id);
    }

    /**
     * Test that shift schedule belongs to work shift.
     */
    public function test_shift_schedule_belongs_to_work_shift(): void
    {
        $shift = WorkShift::factory()->create();
        $schedule = WorkerShiftSchedule::factory()->create(['shift_id' => $shift->id]);

        $this->assertInstanceOf(WorkShift::class, $schedule->shift);
        $this->assertEquals($shift->id, $schedule->shift->id);
    }

    /**
     * Test that scheduled_date is cast to date.
     */
    public function test_scheduled_date_is_cast_to_date(): void
    {
        $date = Carbon::tomorrow();
        $schedule = WorkerShiftSchedule::factory()->create(['scheduled_date' => $date]);

        $this->assertInstanceOf(Carbon::class, $schedule->scheduled_date);
        $this->assertEquals($date->toDateString(), $schedule->scheduled_date->toDateString());
    }

    /**
     * Test shift schedule can have different statuses.
     */
    public function test_shift_schedule_can_have_different_statuses(): void
    {
        $statuses = ['scheduled', 'completed', 'missed', 'cancelled'];

        foreach ($statuses as $status) {
            $schedule = WorkerShiftSchedule::factory()->create(['status' => $status]);
            $this->assertEquals($status, $schedule->status);
        }
    }

    /**
     * Test shift schedule can be created with all fields.
     */
    public function test_shift_schedule_can_be_created(): void
    {
        $employee = Employee::factory()->create();
        $shift = WorkShift::factory()->create();
        $date = Carbon::tomorrow();

        $schedule = WorkerShiftSchedule::factory()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        $this->assertDatabaseHas('worker_shift_schedules', [
            'id' => $schedule->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'status' => 'scheduled',
        ]);
    }
}

