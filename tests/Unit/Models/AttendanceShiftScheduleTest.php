<?php

namespace Tests\Unit\Models;

use App\Models\AttendanceShiftSchedule;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceShiftScheduleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that shift schedule belongs to user.
     */
    public function test_shift_schedule_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $schedule = AttendanceShiftSchedule::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $schedule->user);
        $this->assertEquals($user->id, $schedule->user->id);
    }

    /**
     * Test that shift schedule belongs to work shift.
     */
    public function test_shift_schedule_belongs_to_work_shift(): void
    {
        $shift = WorkShift::factory()->create();
        $schedule = AttendanceShiftSchedule::factory()->create(['shift_id' => $shift->id]);

        $this->assertInstanceOf(WorkShift::class, $schedule->shift);
        $this->assertEquals($shift->id, $schedule->shift->id);
    }

    /**
     * Test that scheduled_date is cast to date.
     */
    public function test_scheduled_date_is_cast_to_date(): void
    {
        $date = Carbon::tomorrow();
        $schedule = AttendanceShiftSchedule::factory()->create(['scheduled_date' => $date]);

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
            $schedule = AttendanceShiftSchedule::factory()->create(['status' => $status]);
            $this->assertEquals($status, $schedule->status);
        }
    }

    /**
     * Test shift schedule can be created with all fields.
     */
    public function test_shift_schedule_can_be_created(): void
    {
        $user = User::factory()->create();
        $shift = WorkShift::factory()->create();
        $date = Carbon::tomorrow();

        $schedule = AttendanceShiftSchedule::factory()->create([
            'user_id' => $user->id,
            'shift_id' => $shift->id,
            'scheduled_date' => $date,
            'status' => 'scheduled',
        ]);

        $this->assertDatabaseHas('attendance_shift_schedules', [
            'id' => $schedule->id,
            'user_id' => $user->id,
            'shift_id' => $shift->id,
            'status' => 'scheduled',
        ]);
    }
}
