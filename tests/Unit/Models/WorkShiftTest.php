<?php

namespace Tests\Unit\Models;

use App\Models\Farm;
use App\Models\WorkShift;
use App\Models\WorkerShiftSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkShiftTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that work shift belongs to farm.
     */
    public function test_work_shift_belongs_to_farm(): void
    {
        $farm = Farm::factory()->create();
        $shift = WorkShift::factory()->create(['farm_id' => $farm->id]);

        $this->assertInstanceOf(Farm::class, $shift->farm);
        $this->assertEquals($farm->id, $shift->farm->id);
    }

    /**
     * Test that work shift has many shift schedules.
     */
    public function test_work_shift_has_many_shift_schedules(): void
    {
        $shift = WorkShift::factory()->create();
        WorkerShiftSchedule::factory()->count(5)->create(['shift_id' => $shift->id]);

        $this->assertCount(5, $shift->shiftSchedules);
        $this->assertInstanceOf(WorkerShiftSchedule::class, $shift->shiftSchedules->first());
    }

    /**
     * Test that start_time and end_time are cast to datetime.
     */
    public function test_time_fields_are_cast_to_datetime(): void
    {
        $shift = WorkShift::factory()->create([
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
        ]);

        $this->assertInstanceOf(\Carbon\Carbon::class, $shift->start_time);
        $this->assertInstanceOf(\Carbon\Carbon::class, $shift->end_time);
    }

    /**
     * Test that work_hours is cast to decimal.
     */
    public function test_work_hours_is_cast_to_decimal(): void
    {
        $shift = WorkShift::factory()->create(['work_hours' => 8.5]);

        $this->assertIsNumeric($shift->work_hours);
        $this->assertEquals(8.5, (float) $shift->work_hours);
    }

    /**
     * Test work shift can be created with all required fields.
     */
    public function test_work_shift_can_be_created(): void
    {
        $farm = Farm::factory()->create();
        $shift = WorkShift::factory()->create([
            'farm_id' => $farm->id,
            'name' => 'Morning Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'work_hours' => 8.0,
        ]);

        $this->assertDatabaseHas('work_shifts', [
            'id' => $shift->id,
            'farm_id' => $farm->id,
            'name' => 'Morning Shift',
        ]);
    }
}

