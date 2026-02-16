<?php

namespace App\Services;

use App\Models\User;
use App\Models\AttendanceShiftSchedule;
use Carbon\Carbon;

class AttendanceWageCalculationService
{
    /**
     * Get required work hours for user on a specific date
     *
     * @param User $user
     * @param Carbon $date
     * @return float Required hours
     */
    public function getRequiredHours(User $user, Carbon $date): float
    {
        $tracking = $user->attendanceTracking;

        if (! $tracking) {
            return 0;
        }

        if ($tracking->work_type === 'shift_based') {
            return $this->getRequiredHoursForShiftBased($user, $date);
        }

        return $this->getRequiredHoursForAdministrative($user, $date);
    }

    private function getRequiredHoursForShiftBased(User $user, Carbon $date): float
    {
        $schedules = AttendanceShiftSchedule::where('user_id', $user->id)
            ->whereDate('scheduled_date', $date->toDateString())
            ->where('status', 'completed')
            ->with('shift')
            ->get();

        $totalHours = 0;

        foreach ($schedules as $schedule) {
            if ($schedule->shift) {
                $totalHours += (float) $schedule->shift->work_hours;
            }
        }

        return $totalHours;
    }

    private function getRequiredHoursForAdministrative(User $user, Carbon $date): float
    {
        $tracking = $user->attendanceTracking;
        $workDays = $tracking->work_days ?? [];
        $dayOfWeek = $date->dayOfWeek;

        $isWorkDay = in_array($dayOfWeek, $workDays);

        if (! $isWorkDay) {
            return 0;
        }

        return (float) ($tracking->work_hours ?? 0);
    }

    /**
     * Calculate base wage for user
     *
     * @param User $user
     * @param float $requiredHours
     * @return int Base wage in currency units
     */
    public function calculateBaseWage(User $user, float $requiredHours): int
    {
        $tracking = $user->attendanceTracking;
        $hourlyWage = $tracking->hourly_wage ?? 0;
        return (int) ($requiredHours * $hourlyWage);
    }

    /**
     * Calculate overtime wage for user
     *
     * @param User $user
     * @param float $overtimeHours
     * @return int Overtime wage in currency units
     */
    public function calculateOvertimeWage(User $user, float $overtimeHours): int
    {
        $tracking = $user->attendanceTracking;
        $overtimeHourlyWage = $tracking->overtime_hourly_wage ?? $tracking->hourly_wage ?? 0;
        return (int) ($overtimeHours * $overtimeHourlyWage);
    }
}
