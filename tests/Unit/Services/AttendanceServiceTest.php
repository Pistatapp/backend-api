<?php

namespace Tests\Unit\Services;

use App\Models\AttendanceSession;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AttendanceService();
    }

    /**
     * Test get or create session creates new session if not exists.
     */
    public function test_get_or_create_session_creates_new_session_if_not_exists(): void
    {
        $user = User::factory()->create();
        $date = Carbon::today();
        $entryTime = $date->copy()->setTime(8, 0, 0);

        $session = $this->service->getOrCreateSession($user, $date, $entryTime);

        $this->assertInstanceOf(AttendanceSession::class, $session);
        $this->assertEquals($user->id, $session->user_id);
        $this->assertEquals($date->toDateString(), $session->date->toDateString());
        $this->assertEquals('in_progress', $session->status);
        $this->assertEquals($entryTime->format('Y-m-d H:i:s'), $session->entry_time->format('Y-m-d H:i:s'));
    }

    /**
     * Test get or create session returns existing session if exists.
     */
    public function test_get_or_create_session_returns_existing_session_if_exists(): void
    {
        $user = User::factory()->create();
        $date = Carbon::today();
        $entryTime = $date->copy()->setTime(8, 0, 0);

        $existingSession = AttendanceSession::factory()->create([
            'user_id' => $user->id,
            'date' => $date->toDateString(),
            'status' => 'completed',
        ]);

        $session = $this->service->getOrCreateSession($user, $date, $entryTime);

        $this->assertEquals($existingSession->id, $session->id);
        $this->assertEquals('completed', $session->status);
    }

    /**
     * Test get active session returns today's in progress session.
     */
    public function test_get_active_session_returns_todays_in_progress_session(): void
    {
        $user = User::factory()->create();
        $todaySession = AttendanceSession::factory()->create([
            'user_id' => $user->id,
            'date' => Carbon::today()->toDateString(),
            'status' => 'in_progress',
        ]);

        AttendanceSession::factory()->create([
            'user_id' => $user->id,
            'date' => Carbon::yesterday()->toDateString(),
            'status' => 'in_progress',
        ]);

        $session = $this->service->getActiveSession($user);

        $this->assertNotNull($session);
        $this->assertEquals($todaySession->id, $session->id);
    }

    /**
     * Test get active session returns null if no active session.
     */
    public function test_get_active_session_returns_null_if_no_active_session(): void
    {
        $user = User::factory()->create();
        AttendanceSession::factory()->create([
            'user_id' => $user->id,
            'date' => Carbon::today(),
            'status' => 'completed',
        ]);

        $session = $this->service->getActiveSession($user);

        $this->assertNull($session);
    }

    /**
     * Test close session updates status and exit time.
     */
    public function test_close_session_updates_status_and_exit_time(): void
    {
        $session = AttendanceSession::factory()->create([
            'status' => 'in_progress',
            'exit_time' => null,
        ]);

        $exitTime = Carbon::now();
        $this->service->closeSession($session, $exitTime);

        $session->refresh();
        $this->assertEquals('completed', $session->status);
        $this->assertEquals($exitTime->format('Y-m-d H:i:s'), $session->exit_time->format('Y-m-d H:i:s'));
    }

    /**
     * Test close session uses current time if exit time not provided.
     */
    public function test_close_session_uses_current_time_if_exit_time_not_provided(): void
    {
        $session = AttendanceSession::factory()->create([
            'status' => 'in_progress',
            'exit_time' => null,
        ]);

        $beforeClose = Carbon::now();
        $this->service->closeSession($session);
        $afterClose = Carbon::now();

        $session->refresh();
        $this->assertEquals('completed', $session->status);
        $this->assertNotNull($session->exit_time);
        $this->assertTrue($session->exit_time->greaterThanOrEqualTo($beforeClose->copy()->subSecond()));
        $this->assertTrue($session->exit_time->lessThanOrEqualTo($afterClose->copy()->addSecond()));
    }

    /**
     * Test get session for date returns correct session.
     */
    public function test_get_session_for_date_returns_correct_session(): void
    {
        $user = User::factory()->create();
        $targetDate = Carbon::parse('2024-11-15');

        $targetSession = AttendanceSession::factory()->create([
            'user_id' => $user->id,
            'date' => $targetDate->toDateString(),
        ]);

        AttendanceSession::factory()->create([
            'user_id' => $user->id,
            'date' => $targetDate->copy()->addDay()->toDateString(),
        ]);

        $session = $this->service->getSessionForDate($user, $targetDate);

        $this->assertNotNull($session);
        $this->assertEquals($targetSession->id, $session->id);
    }

    /**
     * Test get session for date returns null if not found.
     */
    public function test_get_session_for_date_returns_null_if_not_found(): void
    {
        $user = User::factory()->create();
        $date = Carbon::parse('2024-11-15');

        $session = $this->service->getSessionForDate($user, $date);

        $this->assertNull($session);
    }
}
