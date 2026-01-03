<?php

namespace Tests\Unit\Models;

use App\Models\Labour;
use App\Models\WorkShift;
use App\Models\LabourShiftSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class WorkerShiftScheduleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that shift schedule belongs to labour.
     */
    public function test_shift_schedule_belongs_to_employee(): void
    {
        $labour = Labour::factory()->create();
        $schedule = LabourShiftSchedule::factory()->create(['labour_id' => $labour->id]);

        $this->assertInstanceOf(Labour::class, $schedule->labour);
        $this->assertEquals($labour->id, $schedule->labour->id);
    }

    /**
     * Test that shift schedule belongs to work shift.
     */
    public function test_shift_schedule_belongs_to_work_shift(): void
    {
        $shift = WorkShift::factory()->create();
        $schedule = LabourShiftSchedule::factory()->create(['shift_id' => $shift->id]);

        $this->assertInstanceOf(WorkShift::class, $schedule->shift);
        $this->assertEquals($shift->id, $schedule->shift->id);
    }

    /**
     * Test that scheduled_date is cast to date.
     */
    public function test_scheduled_date_is_cast_to_date(): void
    {
        $date = Carbon::tomorrow();
        $schedule = LabourShiftSchedule::factory()->create(['scheduled_date' => $date]);

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
            $schedule = LabourShiftSchedule::factory()->create(['status' => $status]);
            $this->assertEquals($status, $schedule->status);
        }
    }

    /**
     * Test shift schedule can be created with all fields.
     */
    public function test_shift_schedule_can_be_created(): void
    {
        $labour = Labour::factory()->create();
        $shift = WorkShift::factory()->create();
        $date = Carbon::tomorrow();

        $schedule = LabourShiftSchedule::factory()->create([
            'labour_id' => $labour->id,
            'shift_id' => $shift->id,
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        $this->assertDatabaseHas('labour_shift_schedules', [
            'id' => $schedule->id,
            'labour_id' => $labour->id,
            'shift_id' => $shift->id,
            'status' => 'scheduled',
        ]);
    }
}

