<?php

namespace Tests\Unit\Models;

use App\Models\AttendanceSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceSessionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that attendance session belongs to user.
     */
    public function test_attendance_session_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $session = AttendanceSession::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $session->user);
        $this->assertEquals($user->id, $session->user->id);
    }

    /**
     * Test that date is cast to date.
     */
    public function test_date_is_cast_to_date(): void
    {
        $date = Carbon::today();
        $session = AttendanceSession::factory()->create(['date' => $date]);

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

        $session = AttendanceSession::factory()->create([
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
        $user = User::factory()->create();
        $date = Carbon::today();
        $entryTime = Carbon::now()->subHours(8);
        $exitTime = Carbon::now();

        $session = AttendanceSession::factory()->create([
            'user_id' => $user->id,
            'date' => $date,
            'entry_time' => $entryTime,
            'exit_time' => $exitTime,
            'in_zone_duration' => 420,
            'outside_zone_duration' => 60,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('attendance_sessions', [
            'id' => $session->id,
            'user_id' => $user->id,
            'status' => 'completed',
        ]);

        $this->assertEquals(420, $session->in_zone_duration);
        $this->assertEquals(60, $session->outside_zone_duration);
    }

    /**
     * Test attendance session can have in_progress status.
     */
    public function test_attendance_session_can_have_in_progress_status(): void
    {
        $session = AttendanceSession::factory()->create([
            'status' => 'in_progress',
            'exit_time' => null,
        ]);

        $this->assertEquals('in_progress', $session->status);
        $this->assertNull($session->exit_time);
    }
}
