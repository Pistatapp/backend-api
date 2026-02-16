<?php

namespace App\Services;

use App\Models\User;
use App\Models\AttendanceSession;
use Carbon\Carbon;

class AttendanceService
{
    /**
     * Get or create attendance session for user on a specific date
     *
     * @param User $user
     * @param Carbon $date
     * @return AttendanceSession
     */
    public function getOrCreateSession(User $user, Carbon $date): AttendanceSession
    {
        $session = AttendanceSession::where('user_id', $user->id)
            ->whereDate('date', $date)
            ->first();

        if ($session) {
            return $session;
        }

        return AttendanceSession::create([
            'user_id' => $user->id,
            'date' => $date->toDateString(),
            'entry_time' => $date->copy()->startOfDay(),
            'status' => 'in_progress',
        ]);
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
        $session->update([
            'exit_time' => $exitTime ?? Carbon::now(),
            'status' => 'completed',
        ]);
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
