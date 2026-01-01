<?php

namespace App\Services;

use App\Models\Labour;
use App\Models\LabourShiftSchedule;
use Carbon\Carbon;

class LabourWageCalculationService
{
    /**
     * Get required work hours for labour on a specific date
     *
     * @param Labour $labour
     * @param Carbon $date
     * @return float Required hours
     */
    public function getRequiredHours(Labour $labour, Carbon $date): float
    {
        if ($labour->work_type === 'shift_based') {
            return $this->getRequiredHoursForShiftBased($labour, $date);
        } else {
            // administrative
            return $this->getRequiredHoursForAdministrative($labour, $date);
        }
    }

    /**
     * Get required hours for shift-based labour
     *
     * @param Labour $labour
     * @param Carbon $date
     * @return float
     */
    private function getRequiredHoursForShiftBased(Labour $labour, Carbon $date): float
    {
        // Get completed shifts for this date
        // Only count 'completed' status shifts as wages should only be calculated for completed work
        // Use whereDate to handle date comparison properly
        $schedules = LabourShiftSchedule::where('labour_id', $labour->id)
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

    /**
     * Get required hours for administrative labour
     *
     * @param Labour $labour
     * @param Carbon $date
     * @return float
     */
    private function getRequiredHoursForAdministrative(Labour $labour, Carbon $date): float
    {
        // Check if this day is a work day
        $workDays = $labour->work_days ?? [];
        $dayOfWeek = $date->dayOfWeek; // 0 = Sunday, 6 = Saturday

        // Convert to Persian week day if needed (Saturday = 0, Friday = 6)
        // Adjust based on your calendar system
        $isWorkDay = in_array($dayOfWeek, $workDays);

        if (!$isWorkDay) {
            return 0;
        }

        // Return work hours from labour record
        return (float) ($labour->work_hours ?? 0);
    }

    /**
     * Calculate base wage for labour
     *
     * @param Labour $labour
     * @param float $requiredHours
     * @return int Base wage in currency units
     */
    public function calculateBaseWage(Labour $labour, float $requiredHours): int
    {
        $hourlyWage = $labour->hourly_wage ?? 0;
        return (int) ($requiredHours * $hourlyWage);
    }

    /**
     * Calculate overtime wage for labour
     *
     * @param Labour $labour
     * @param float $overtimeHours
     * @return int Overtime wage in currency units
     */
    public function calculateOvertimeWage(Labour $labour, float $overtimeHours): int
    {
        $overtimeHourlyWage = $labour->overtime_hourly_wage ?? $labour->hourly_wage ?? 0;
        return (int) ($overtimeHours * $overtimeHourlyWage);
    }
}

