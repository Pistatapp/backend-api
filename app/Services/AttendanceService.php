<?php

namespace App\Services;

use App\Models\User;
use App\Models\AttendanceSession;
use Carbon\Carbon;

class AttendanceService
{
    /**
     * Get or create attendance session for user on a specific date.
     * Session is only created when the first GPS point inside the farm boundary is received;
     * entry_time is set to that GPS time.
     *
     * @param User $user
     * @param Carbon $dateTime
     * @param bool $isInBoundary
     * @return AttendanceSession
     */
    public function getOrCreateSession(User $user, Carbon $dateTime, bool $isInBoundary): AttendanceSession
    {
        $entryTime = $isInBoundary ? $dateTime->format('H:i:s') : null;
        $status = $isInBoundary ? 'in_progress' : 'pending';

        return AttendanceSession::firstOrCreate(
            [
                'user_id' => $user->id,
                'date' => $dateTime->toDateString(),
            ],
            [
                'entry_time' => $entryTime,
                'status' => $status,
            ]
        );
    }

    /**
     * Get active session for user (today's session if in progress)
     *
     * @param User $user
     * @return AttendanceSession|null
     */
    public function getActiveSession(User $user): ?AttendanceSession
    {
        return AttendanceSession::where('user_id', $user->id)
            ->whereDate('date', Carbon::today())
            ->where('status', 'in_progress')
            ->first();
    }

    /**
     * Close attendance session
     *
     * @param AttendanceSession $session
     * @param Carbon|null $exitTime
     * @return void
     */
    public function closeSession(AttendanceSession $session, ?Carbon $exitTime = null): void
    {
        $exitTime = $exitTime ? $exitTime->format('H:i:s') : now()->toTimeString();
        $session->update(['exit_time' => $exitTime, 'status' => 'completed']);
    }

    /**
     * Get session for a specific date
     *
     * @param User $user
     * @param Carbon $date
     * @return AttendanceSession|null
     */
    public function getSessionForDate(User $user, Carbon $date): ?AttendanceSession
    {
        return AttendanceSession::where('user_id', $user->id)
            ->whereDate('date', $date)
            ->first();
    }
}
