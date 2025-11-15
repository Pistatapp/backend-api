<?php

namespace Tests\Unit\Models;

use App\Models\Employee;
use App\Models\WorkerAttendanceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class WorkerAttendanceSessionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that attendance session belongs to employee.
     */
    public function test_attendance_session_belongs_to_employee(): void
    {
        $employee = Employee::factory()->create();
        $session = WorkerAttendanceSession::factory()->create(['employee_id' => $employee->id]);

        $this->assertInstanceOf(Employee::class, $session->employee);
        $this->assertEquals($employee->id, $session->employee->id);
    }

    /**
     * Test that date is cast to date.
     */
    public function test_date_is_cast_to_date(): void
    {
        $date = Carbon::today();
        $session = WorkerAttendanceSession::factory()->create(['date' => $date]);

        $this->assertInstanceOf(Carbon::class, $session->date);
        $this->assertEquals($date->toDateString(), $session->date->toDateString());
    }

    /**
     * Test that entry_time and exit_time are cast to datetime.
     */
    public function test_time_fields_are_cast_to_datetime(): void
    {
        $entryTime = Carbon::now()->subHours(8);
        $exitTime = Carbon::now();

        $session = WorkerAttendanceSession::factory()->create([
            'entry_time' => $entryTime,
            'exit_time' => $exitTime,
        ]);

        $this->assertInstanceOf(Carbon::class, $session->entry_time);
        $this->assertInstanceOf(Carbon::class, $session->exit_time);
    }

    /**
     * Test attendance session can be created with all fields.
     */
    public function test_attendance_session_can_be_created(): void
    {
        $employee = Employee::factory()->create();
        $date = Carbon::today();
        $entryTime = Carbon::now()->subHours(8);
        $exitTime = Carbon::now();

        $session = WorkerAttendanceSession::factory()->create([
            'employee_id' => $employee->id,
            'date' => $date,
            'entry_time' => $entryTime,
            'exit_time' => $exitTime,
            'total_in_zone_duration' => 420, // 7 hours
            'total_out_zone_duration' => 60, // 1 hour
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('worker_attendance_sessions', [
            'id' => $session->id,
            'employee_id' => $employee->id,
            'status' => 'completed',
        ]);

        $this->assertEquals(420, $session->total_in_zone_duration);
        $this->assertEquals(60, $session->total_out_zone_duration);
    }

    /**
     * Test attendance session can have in_progress status.
     */
    public function test_attendance_session_can_have_in_progress_status(): void
    {
        $session = WorkerAttendanceSession::factory()->create([
            'status' => 'in_progress',
            'exit_time' => null,
        ]);

        $this->assertEquals('in_progress', $session->status);
        $this->assertNull($session->exit_time);
    }
}

