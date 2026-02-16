<?php

namespace App\Jobs;

use App\Models\AttendanceSession;
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

    public $tries = 3;

    public function handle(): void
    {
        $cutoffTime = Carbon::now()->subHour();

        $staleSessions = AttendanceSession::where('status', 'in_progress')
            ->where('updated_at', '<', $cutoffTime)
            ->get();

        foreach ($staleSessions as $session) {
            $session->update([
                'exit_time' => $session->updated_at,
                'status' => 'completed',
            ]);

            Log::info('Closed stale attendance session', [
                'session_id' => $session->id,
                'user_id' => $session->user_id,
            ]);
        }

        $previousDaysSessions = AttendanceSession::where('status', 'in_progress')
            ->where('date', '<', Carbon::today()->toDateString())
            ->get();

        foreach ($previousDaysSessions as $session) {
            $endOfDay = Carbon::parse($session->date)->endOfDay();
            $session->update([
                'exit_time' => $endOfDay,
                'status' => 'completed',
            ]);

            Log::info('Closed previous day attendance session', [
                'session_id' => $session->id,
                'user_id' => $session->user_id,
                'date' => $session->date->toDateString(),
            ]);
        }
    }
}
