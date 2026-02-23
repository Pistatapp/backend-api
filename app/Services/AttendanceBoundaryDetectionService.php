<?php

namespace App\Services;

use App\Models\User;
use App\Models\AttendanceSession;
use Carbon\Carbon;
use App\Events\AttendanceUpdated;

class AttendanceBoundaryDetectionService
{
    public function __construct(
        private AttendanceService $attendanceService,
    ) {}

    /**
     * Process GPS point and check if user is in orchard boundary.
     * Entry time is set on the first GPS point inside the boundary.
     * Exit time is set when the client sends exit=true in the GPS payload.
     *
     * @param User $user
     * @param array $coordinate [(float)lat, (float)long]
     * @param Carbon $dateTime
     * @param bool $exit True when the client reports the user has left the boundary
     * @return void
     */
    public function processGpsPoint(User $user, array $gpsData): void
    {
        $user->load('attendanceTracking.farm');

        $attendanceTracking = $user->attendanceTracking;
        $dateTime = Carbon::createFromTimestampMs($gpsData['time']);

        $farm = $attendanceTracking->farm;

        $lat = $gpsData['coordinate'][0];
        $lng = $gpsData['coordinate'][1];
        $point = [$lng, $lat];

        $isInBoundary = is_point_in_polygon($point, $farm->coordinates);

        $session = $this->attendanceService->getOrCreateSession($user, $dateTime, $isInBoundary);

        if ($gpsData['exit'] && $session->status === 'in_progress') {
            $this->attendanceService->closeSession($session, $dateTime);
        }

        $this->updateTimeTracking($session, $user, $isInBoundary, $dateTime);

        event(new AttendanceUpdated($user, $session));
    }

    private function updateTimeTracking(AttendanceSession $session, User $user, bool $isInBoundary, Carbon $dateTime): void
    {
        $secondsDiff = $dateTime->diffInSeconds($session->updated_at);
        $totalInZoneDuration = $session->in_zone_duration;
        $totalOutZoneDuration = $session->outside_zone_duration;

        if ($secondsDiff > 0) {
            if ($isInBoundary) {
                $totalInZoneDuration += $secondsDiff;
            } else {
                $totalOutZoneDuration += $secondsDiff;
            }
        }

        $expectedWorkHours = $this->getExpectedWorkHours($user, $dateTime);

        $efficiency = $this->calculateEfficiency($totalInZoneDuration, $expectedWorkHours);

        $session->update([
            'updated_at' => $dateTime,
            'in_zone_duration' => $totalInZoneDuration,
            'outside_zone_duration' => $totalOutZoneDuration,
            'efficiency' => $efficiency,
        ]);
    }

    /**
     * efficiency = (in_zone_duration in seconds / work_hours in seconds) * 100
     * work_hours: from attendance_tracking for administrative, from scheduled shift for today for shift_based.
     */
    private function calculateEfficiency(int $totalInZoneDuration, float $expectedWorkHours): float
    {
        $expectedWorkTimeSeconds = $expectedWorkHours * 3600;

        if ($expectedWorkTimeSeconds <= 0) {
            return 0;
        }

        $efficiency = ($totalInZoneDuration / $expectedWorkTimeSeconds) * 100;

        return round($efficiency, 2);
    }

    /**
     * Get expected work hours for user on a specific date
     *
     * @param User $user
     * @param Carbon $dateTime
     * @return int Expected work hours in seconds
     */
    private function getExpectedWorkHours(User $user, Carbon $dateTime): int
    {
        $tracking = $user->attendanceTracking;
        $date = $dateTime->toDateString();


        if ($tracking->work_type === 'administrative') {
            return $tracking->work_hours;
        }

        $shiftSchedule = $user->shiftSchedules()
            ->where('scheduled_date', $date)
            ->with('shift')->first();

        if (! $shiftSchedule) {
            return 0;
        }

        return $shiftSchedule->shift->work_hours;
    }
}
