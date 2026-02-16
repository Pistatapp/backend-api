<?php

namespace App\Services;

use App\Models\AttendanceSession;

class AttendanceProductivityCalculator
{
    /**
     * Calculate productivity score based on time in orchard boundaries
     * Formula: (time_in_zone / total_attendance_time) × 100
     *
     * @param AttendanceSession $session
     * @return float|null Productivity score (0-100) or null if no attendance time
     */
    public function calculate(AttendanceSession $session): ?float
    {
        $totalInZone = $session->total_in_zone_duration;
        $totalOutZone = $session->total_out_zone_duration;
        $totalAttendance = $totalInZone + $totalOutZone;

        if ($totalAttendance <= 0) {
            return null;
        }

        $productivity = ($totalInZone / $totalAttendance) * 100;

        return round($productivity, 2);
    }

    /**
     * Calculate productivity from time values directly
     *
     * @param int $inZoneMinutes
     * @param int $outZoneMinutes
     * @return float|null
     */
    public function calculateFromTimes(int $inZoneMinutes, int $outZoneMinutes): ?float
    {
        $total = $inZoneMinutes + $outZoneMinutes;

        if ($total <= 0) {
            return null;
        }

        return round(($inZoneMinutes / $total) * 100, 2);
    }
}
