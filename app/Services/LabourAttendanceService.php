<?php

namespace App\Services;

use App\Models\Labour;
use App\Models\LabourAttendanceSession;
use Carbon\Carbon;

class LabourAttendanceService
{
    /**
     * Get or create attendance session for labour on a specific date
     *
     * @param Labour $labour
     * @param Carbon $date
     * @return LabourAttendanceSession
     */
    public function getOrCreateSession(Labour $labour, Carbon $date): LabourAttendanceSession
    {
        // Use whereDate for reliable date comparison across different databases
        $session = LabourAttendanceSession::where('labour_id', $labour->id)
            ->whereDate('date', $date)
            ->first();

        if ($session) {
            return $session;
        }

        // Create new session if not found
        return LabourAttendanceSession::create([
            'labour_id' => $labour->id,
            'date' => $date->toDateString(),
            'entry_time' => $date->copy()->startOfDay(),
            'status' => 'in_progress',
        ]);
    }

    /**
     * Get active session for labour (today's session if in progress)
     *
     * @param Labour $labour
     * @return LabourAttendanceSession|null
     */
    public function getActiveSession(Labour $labour): ?LabourAttendanceSession
    {
        return LabourAttendanceSession::where('labour_id', $labour->id)
            ->whereDate('date', Carbon::today())
            ->where('status', 'in_progress')
            ->first();
    }

    /**
     * Close attendance session
     *
     * @param LabourAttendanceSession $session
     * @param Carbon|null $exitTime
     * @return void
     */
    public function closeSession(LabourAttendanceSession $session, ?Carbon $exitTime = null): void
    {
        $session->update([
            'exit_time' => $exitTime ?? Carbon::now(),
            'status' => 'completed',
        ]);
    }

    /**
     * Get session for a specific date
     *
     * @param Labour $labour
     * @param Carbon $date
     * @return LabourAttendanceSession|null
     */
    public function getSessionForDate(Labour $labour, Carbon $date): ?LabourAttendanceSession
    {
        return LabourAttendanceSession::where('labour_id', $labour->id)
            ->whereDate('date', $date)
            ->first();
    }
}

