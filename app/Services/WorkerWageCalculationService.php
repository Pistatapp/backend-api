<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\WorkerShiftSchedule;
use Carbon\Carbon;

class WorkerWageCalculationService
{
    /**
     * Get required work hours for employee on a specific date
     *
     * @param Employee $employee
     * @param Carbon $date
     * @return float Required hours
     */
    public function getRequiredHours(Employee $employee, Carbon $date): float
    {
        if ($employee->work_type === 'shift_based') {
            return $this->getRequiredHoursForShiftBased($employee, $date);
        } else {
            // administrative
            return $this->getRequiredHoursForAdministrative($employee, $date);
        }
    }

    /**
     * Get required hours for shift-based worker
     *
     * @param Employee $employee
     * @param Carbon $date
     * @return float
     */
    private function getRequiredHoursForShiftBased(Employee $employee, Carbon $date): float
    {
        // Get completed shifts for this date
        // Only count 'completed' status shifts as wages should only be calculated for completed work
        // Use whereDate to handle date comparison properly
        $schedules = WorkerShiftSchedule::where('employee_id', $employee->id)
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
     * Get required hours for administrative worker
     *
     * @param Employee $employee
     * @param Carbon $date
     * @return float
     */
    private function getRequiredHoursForAdministrative(Employee $employee, Carbon $date): float
    {
        // Check if this day is a work day
        $workDays = $employee->work_days ?? [];
        $dayOfWeek = $date->dayOfWeek; // 0 = Sunday, 6 = Saturday

        // Convert to Persian week day if needed (Saturday = 0, Friday = 6)
        // Adjust based on your calendar system
        $isWorkDay = in_array($dayOfWeek, $workDays);

        if (!$isWorkDay) {
            return 0;
        }

        // Return work hours from employee record
        return (float) ($employee->work_hours ?? 0);
    }

    /**
     * Calculate base wage for employee
     *
     * @param Employee $employee
     * @param float $requiredHours
     * @return int Base wage in currency units
     */
    public function calculateBaseWage(Employee $employee, float $requiredHours): int
    {
        $hourlyWage = $employee->hourly_wage ?? 0;
        return (int) ($requiredHours * $hourlyWage);
    }

    /**
     * Calculate overtime wage for employee
     *
     * @param Employee $employee
     * @param float $overtimeHours
     * @return int Overtime wage in currency units
     */
    public function calculateOvertimeWage(Employee $employee, float $overtimeHours): int
    {
        $overtimeHourlyWage = $employee->overtime_hourly_wage ?? $employee->hourly_wage ?? 0;
        return (int) ($overtimeHours * $overtimeHourlyWage);
    }
}

