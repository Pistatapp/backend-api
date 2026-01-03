<?php

namespace Tests\Unit\Services;

use App\Models\Labour;
use App\Models\LabourAttendanceSession;
use App\Services\LabourAttendanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerAttendanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private LabourAttendanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LabourAttendanceService();
    }

    /**
     * Test get or create session creates new session if not exists.
     */
    public function test_get_or_create_session_creates_new_session_if_not_exists(): void
    {
        $labour = Labour::factory()->create();
        $date = Carbon::today();

        $session = $this->service->getOrCreateSession($labour, $date);

        $this->assertInstanceOf(LabourAttendanceSession::class, $session);
        $this->assertEquals($labour->id, $session->labour_id);
        $this->assertEquals($date->toDateString(), $session->date->toDateString());
        $this->assertEquals('in_progress', $session->status);
    }

    /**
     * Test get or create session returns existing session if exists.
     */
    public function test_get_or_create_session_returns_existing_session_if_exists(): void
    {
        $labour = Labour::factory()->create();
        $date = Carbon::today();

        $existingSession = LabourAttendanceSession::factory()->create([
            'labour_id' => $labour->id,
            'date' => $date->toDateString(), // Use toDateString for consistency
            'status' => 'completed',
        ]);

        $session = $this->service->getOrCreateSession($labour, $date);

        $this->assertEquals($existingSession->id, $session->id);
        $this->assertEquals('completed', $session->status);
    }

    /**
     * Test get active session returns today's in progress session.
     */
    public function test_get_active_session_returns_todays_in_progress_session(): void
    {
        $labour = Labour::factory()->create();
        $todaySession = LabourAttendanceSession::factory()->create([
            'labour_id' => $labour->id,
            'date' => Carbon::today()->toDateString(), // Use toDateString for consistency
            'status' => 'in_progress',
        ]);

        LabourAttendanceSession::factory()->create([
            'labour_id' => $labour->id,
            'date' => Carbon::yesterday()->toDateString(),
            'status' => 'in_progress',
        ]);

        $session = $this->service->getActiveSession($labour);

        $this->assertNotNull($session);
        $this->assertEquals($todaySession->id, $session->id);
    }

    /**
     * Test get active session returns null if no active session.
     */
    public function test_get_active_session_returns_null_if_no_active_session(): void
    {
        $labour = Labour::factory()->create();
        LabourAttendanceSession::factory()->create([
            'labour_id' => $labour->id,
            'date' => Carbon::today(),
            'status' => 'completed',
        ]);

        $session = $this->service->getActiveSession($labour);

        $this->assertNull($session);
    }

    /**
     * Test close session updates status and exit time.
     */
    public function test_close_session_updates_status_and_exit_time(): void
    {
        $session = LabourAttendanceSession::factory()->create([
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
        $session = LabourAttendanceSession::factory()->create([
            'status' => 'in_progress',
            'exit_time' => null,
        ]);

        $beforeClose = Carbon::now();
        $this->service->closeSession($session);
        $afterClose = Carbon::now();

        $session->refresh();
        $this->assertEquals('completed', $session->status);
        $this->assertNotNull($session->exit_time);
        // Check that exit_time is between beforeClose and afterClose (allow 1 second tolerance for timing)
        $this->assertTrue($session->exit_time->greaterThanOrEqualTo($beforeClose->copy()->subSecond()));
        $this->assertTrue($session->exit_time->lessThanOrEqualTo($afterClose->copy()->addSecond()));
    }

    /**
     * Test get session for date returns correct session.
     */
    public function test_get_session_for_date_returns_correct_session(): void
    {
        $labour = Labour::factory()->create();
        $targetDate = Carbon::parse('2024-11-15');

        $targetSession = LabourAttendanceSession::factory()->create([
            'labour_id' => $labour->id,
            'date' => $targetDate->toDateString(), // Use toDateString for consistency
        ]);

        LabourAttendanceSession::factory()->create([
            'labour_id' => $labour->id,
            'date' => $targetDate->copy()->addDay()->toDateString(),
        ]);

        $session = $this->service->getSessionForDate($labour, $targetDate);

        $this->assertNotNull($session);
        $this->assertEquals($targetSession->id, $session->id);
    }

    /**
     * Test get session for date returns null if not found.
     */
    public function test_get_session_for_date_returns_null_if_not_found(): void
    {
        $labour = Labour::factory()->create();
        $date = Carbon::parse('2024-11-15');

        $session = $this->service->getSessionForDate($labour, $date);

        $this->assertNull($session);
    }
}

