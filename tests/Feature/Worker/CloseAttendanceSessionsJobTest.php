<?php

namespace Tests\Feature\Worker;

use App\Jobs\CloseAttendanceSessionsJob;
use App\Models\Labour;
use App\Models\LabourAttendanceSession;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CloseAttendanceSessionsJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test job closes stale in-progress sessions.
     */
    public function test_job_closes_stale_in_progress_sessions(): void
    {
        Log::spy();

        $labour1 = Labour::factory()->create();
        $labour2 = Labour::factory()->create();

        // Stale session (updated 2 hours ago)
        $staleSession = LabourAttendanceSession::factory()->create([
            'labour_id' => $labour1->id,
            'date' => Carbon::today(),
            'status' => 'in_progress',
            'updated_at' => Carbon::now()->subHours(2),
            'entry_time' => Carbon::now()->subHours(3),
            'exit_time' => null,
        ]);

        // Active session (updated 30 minutes ago)
        $activeSession = LabourAttendanceSession::factory()->create([
            'labour_id' => $labour2->id,
            'date' => Carbon::today(),
            'status' => 'in_progress',
            'updated_at' => Carbon::now()->subMinutes(30),
            'entry_time' => Carbon::now()->subHours(1),
            'exit_time' => null,
        ]);

        $job = new CloseAttendanceSessionsJob();
        $job->handle();

        $staleSession->refresh();
        $activeSession->refresh();

        $this->assertEquals('completed', $staleSession->status);
        $this->assertNotNull($staleSession->exit_time);
        $this->assertEquals('in_progress', $activeSession->status);

        Log::assertLogged('info', function ($message, $context) use ($staleSession) {
            return str_contains($message, 'Closed stale attendance session') &&
                   $context['session_id'] === $staleSession->id;
        });
    }

    /**
     * Test job closes previous day sessions.
     */
    public function test_job_closes_previous_day_sessions(): void
    {
        Log::spy();

        $labour = Labour::factory()->create();

        // Session from yesterday still in progress
        $yesterdaySession = LabourAttendanceSession::factory()->create([
            'labour_id' => $labour->id,
            'date' => Carbon::yesterday(),
            'status' => 'in_progress',
            'entry_time' => Carbon::yesterday()->setTime(8, 0, 0),
            'exit_time' => null,
        ]);

        // Today's session (should remain open)
        $todaySession = LabourAttendanceSession::factory()->create([
            'labour_id' => $labour->id,
            'date' => Carbon::today(),
            'status' => 'in_progress',
            'entry_time' => Carbon::today()->setTime(8, 0, 0),
            'exit_time' => null,
        ]);

        $job = new CloseAttendanceSessionsJob();
        $job->handle();

        $yesterdaySession->refresh();
        $todaySession->refresh();

        $this->assertEquals('completed', $yesterdaySession->status);
        $this->assertNotNull($yesterdaySession->exit_time);
        // Exit time should be end of that day
        $this->assertEquals(
            Carbon::yesterday()->endOfDay()->format('Y-m-d H:i:s'),
            $yesterdaySession->exit_time->format('Y-m-d H:i:s')
        );

        $this->assertEquals('in_progress', $todaySession->status);

        Log::assertLogged('info', function ($message, $context) use ($yesterdaySession) {
            return str_contains($message, 'Closed previous day attendance session') &&
                   $context['session_id'] === $yesterdaySession->id;
        });
    }

    /**
     * Test job sets exit time to last update time for stale sessions.
     */
    public function test_job_sets_exit_time_to_last_update_time_for_stale_sessions(): void
    {
        $labour = Labour::factory()->create();

        $lastUpdate = Carbon::now()->subHours(2);
        $staleSession = LabourAttendanceSession::factory()->create([
            'labour_id' => $labour->id,
            'date' => Carbon::today(),
            'status' => 'in_progress',
            'updated_at' => $lastUpdate,
            'entry_time' => Carbon::now()->subHours(3),
            'exit_time' => null,
        ]);

        $job = new CloseAttendanceSessionsJob();
        $job->handle();

        $staleSession->refresh();
        $this->assertEquals(
            $lastUpdate->format('Y-m-d H:i:s'),
            $staleSession->exit_time->format('Y-m-d H:i:s')
        );
    }

    /**
     * Test job does not close completed sessions.
     */
    public function test_job_does_not_close_completed_sessions(): void
    {
        $labour = Labour::factory()->create();

        $completedSession = LabourAttendanceSession::factory()->create([
            'labour_id' => $labour->id,
            'date' => Carbon::today(),
            'status' => 'completed',
            'updated_at' => Carbon::now()->subHours(2),
            'exit_time' => Carbon::now()->subHours(1),
        ]);

        $job = new CloseAttendanceSessionsJob();
        $job->handle();

        $completedSession->refresh();
        $this->assertEquals('completed', $completedSession->status);
    }

    /**
     * Test job handles empty sessions gracefully.
     */
    public function test_job_handles_empty_sessions_gracefully(): void
    {
        // No sessions created

        $job = new CloseAttendanceSessionsJob();
        
        // Should not throw exception
        $job->handle();

        $this->assertTrue(true);
    }

    /**
     * Test job handles multiple stale sessions.
     */
    public function test_job_handles_multiple_stale_sessions(): void
    {
        $labour1 = Labour::factory()->create();
        $labour2 = Labour::factory()->create();

        $staleSession1 = LabourAttendanceSession::factory()->create([
            'labour_id' => $labour1->id,
            'date' => Carbon::today(),
            'status' => 'in_progress',
            'updated_at' => Carbon::now()->subHours(2),
        ]);

        $staleSession2 = LabourAttendanceSession::factory()->create([
            'labour_id' => $labour2->id,
            'date' => Carbon::today(),
            'status' => 'in_progress',
            'updated_at' => Carbon::now()->subHours(3),
        ]);

        $job = new CloseAttendanceSessionsJob();
        $job->handle();

        $staleSession1->refresh();
        $staleSession2->refresh();

        $this->assertEquals('completed', $staleSession1->status);
        $this->assertEquals('completed', $staleSession2->status);
    }
}

