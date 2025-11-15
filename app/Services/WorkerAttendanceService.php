<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\WorkerAttendanceSession;
use Carbon\Carbon;

class WorkerAttendanceService
{
    /**
     * Get or create attendance session for employee on a specific date
     *
     * @param Employee $employee
     * @param Carbon $date
     * @return WorkerAttendanceSession
     */
    public function getOrCreateSession(Employee $employee, Carbon $date): WorkerAttendanceSession
    {
        // Use whereDate for reliable date comparison across different databases
        $session = WorkerAttendanceSession::where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->first();

        if ($session) {
            return $session;
        }

        // Create new session if not found
        return WorkerAttendanceSession::create([
            'employee_id' => $employee->id,
            'date' => $date->toDateString(),
            'entry_time' => $date->copy()->startOfDay(),
            'status' => 'in_progress',
        ]);
    }

    /**
     * Get active session for employee (today's session if in progress)
     *
     * @param Employee $employee
     * @return WorkerAttendanceSession|null
     */
    public function getActiveSession(Employee $employee): ?WorkerAttendanceSession
    {
        return WorkerAttendanceSession::where('employee_id', $employee->id)
            ->whereDate('date', Carbon::today())
            ->where('status', 'in_progress')
            ->first();
    }

    /**
     * Close attendance session
     *
     * @param WorkerAttendanceSession $session
     * @param Carbon|null $exitTime
     * @return void
     */
    public function closeSession(WorkerAttendanceSession $session, ?Carbon $exitTime = null): void
    {
        $session->update([
            'exit_time' => $exitTime ?? Carbon::now(),
            'status' => 'completed',
        ]);
    }

    /**
     * Get session for a specific date
     *
     * @param Employee $employee
     * @param Carbon $date
     * @return WorkerAttendanceSession|null
     */
    public function getSessionForDate(Employee $employee, Carbon $date): ?WorkerAttendanceSession
    {
        return WorkerAttendanceSession::where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->first();
    }
}

