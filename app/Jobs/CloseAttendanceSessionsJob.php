<?php

namespace App\Jobs;

use App\Models\LabourAttendanceSession;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CloseAttendanceSessionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        // Close sessions that are in progress but haven't been updated in the last hour
        $cutoffTime = Carbon::now()->subHour();

        $staleSessions = LabourAttendanceSession::where('status', 'in_progress')
            ->where('updated_at', '<', $cutoffTime)
            ->get();

        foreach ($staleSessions as $session) {
            // Set exit time to last update time
            $session->update([
                'exit_time' => $session->updated_at,
                'status' => 'completed',
            ]);

            Log::info('Closed stale attendance session', [
                'session_id' => $session->id,
                'labour_id' => $session->labour_id,
            ]);
        }

        // Also close sessions from previous days that are still in progress
        $previousDaysSessions = LabourAttendanceSession::where('status', 'in_progress')
            ->where('date', '<', Carbon::today()->toDateString())
            ->get();

        foreach ($previousDaysSessions as $session) {
            // Set exit time to end of that day
            $endOfDay = Carbon::parse($session->date)->endOfDay();
            $session->update([
                'exit_time' => $endOfDay,
                'status' => 'completed',
            ]);

            Log::info('Closed previous day attendance session', [
                'session_id' => $session->id,
                'labour_id' => $session->labour_id,
                'date' => $session->date->toDateString(),
            ]);
        }
    }
}
