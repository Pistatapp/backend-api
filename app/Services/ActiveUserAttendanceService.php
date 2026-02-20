<?php

namespace App\Services;

use App\Models\User;
use App\Models\Farm;
use App\Models\AttendanceGpsData;
use Carbon\Carbon;

class ActiveUserAttendanceService
{
    /**
     * Get active users with attendance tracking for a farm
     *
     * @param Farm $farm
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveUsers(Farm $farm)
    {
        $today = Carbon::today();
        $users = $this->fetchUsersWithRelationships($farm, $today);

        return $users->map(function ($user) use ($farm, $today) {
            return $this->buildUserData($user, $farm, $today);
        });
    }

    /**
     * Fetch users with attendance tracking enabled for a farm with required relationships
     *
     * @param Farm $farm
     * @param Carbon $date
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function fetchUsersWithRelationships(Farm $farm, Carbon $date)
    {
        return User::whereHas('attendanceTracking', function ($query) use ($farm) {
            $query->where('farm_id', $farm->id)->where('enabled', true);
        })->with([
            'profile:id,user_id,name',
            'attendanceGpsData' => function ($query) {
                $query->orderBy('date_time', 'desc')->limit(1);
            },
            'shiftSchedules' => function ($query) use ($date) {
                $query->whereDate('scheduled_date', $date)->with('shift');
            }
        ])->get();
    }

    /**
     * Build user data array with attendance information
     *
     * @param User $user
     * @param Farm $farm
     * @param Carbon $date
     * @return array
     */
    private function buildUserData(User $user, Farm $farm, Carbon $date): array
    {
        $latestGps = $user->attendanceGpsData->first();
        $isInZone = $this->isUserInZone($user, $farm, $latestGps?->coordinate);
        $status = $this->calculateStatus($user, $farm, $date, $isInZone);

        $entranceGps = $this->getEntranceGps($user, $date);
        $entranceTime = $entranceGps ? $entranceGps->date_time->format('H:i') : null;
        $totalWorkDuration = $this->getTotalWorkDuration($user, $date, $entranceGps);

        return [
            'id' => $user->id,
            'name' => $user->profile?->name ?? '',
            'status' => $status,
            'entrance_time' => $entranceTime,
            'total_work_duration' => $totalWorkDuration,
        ];
    }

    /**
     * Get the first GPS point during the scheduled shift
     *
     * @param User $user
     * @param Carbon $date
     * @return AttendanceGpsData|null
     */
    private function getEntranceGps(User $user, Carbon $date): ?AttendanceGpsData
    {
        $shiftRange = $this->getShiftTimeRange($user, $date);

        if (! $shiftRange['start'] || ! $shiftRange['end']) {
            return null;
        }

        return AttendanceGpsData::where('user_id', $user->id)
            ->whereBetween('date_time', [$shiftRange['start'], $shiftRange['end']])
            ->orderBy('date_time', 'asc')
            ->first();
    }

    /**
     * Check if user is currently in orchard zone
     *
     * @param User $user
     * @param Farm $farm
     * @param array|null $coordinate
     * @return bool
     */
    public function isUserInZone(User $user, Farm $farm, ?array $coordinate): bool
    {
        if (! $coordinate) {
            return false;
        }

        if (! $farm->coordinates) {
            return false;
        }

        $point = [$coordinate['lng'], $coordinate['lat']];
        return is_point_in_polygon($point, $farm->coordinates);
    }

    /**
     * Calculate attendance status for a user based on shift schedule and location
     *
     * @param User $user
     * @param Farm $farm
     * @param Carbon $date
     * @param bool $isInZone
     * @return string|null
     */
    private function calculateStatus(User $user, Farm $farm, Carbon $date, bool $isInZone): ?string
    {
        // Get today's shift schedule for the user (already filtered in the query)
        $shiftSchedule = $user->shiftSchedules->first();

        // If no shift schedule exists, return null
        if (! $shiftSchedule || ! $shiftSchedule->shift) {
            return null;
        }

        $shift = $shiftSchedule->shift;
        $now = Carbon::now();

        // Calculate shift start and end times for the scheduled date
        $shiftStart = $date->copy()->setTime(
            $shift->start_time->hour,
            $shift->start_time->minute,
            $shift->start_time->second
        );
        $shiftEnd = $date->copy()->setTime(
            $shift->end_time->hour,
            $shift->end_time->minute,
            $shift->end_time->second
        );

        // Handle midnight crossing (e.g., 22:00 - 02:00)
        if ($shiftEnd->lt($shiftStart)) {
            $shiftEnd->addDay();
        }

        // Check if current time is within the shift schedule
        $isWithinShift = $now->gte($shiftStart) && $now->lt($shiftEnd);

        if ($isWithinShift) {
            // During shift time: present if in zone, absent if not
            return $isInZone ? 'present' : 'absent';
        } else {
            // Outside shift time: resting
            return 'resting';
        }
    }

    /**
     * Get shift time range (start and end) for a user's scheduled shift
     *
     * @param User $user
     * @param Carbon $date
     * @return array{start: Carbon|null, end: Carbon|null}
     */
    private function getShiftTimeRange(User $user, Carbon $date): array
    {
        $shiftSchedule = $user->shiftSchedules->first();

        if (! $shiftSchedule || ! $shiftSchedule->shift) {
            return ['start' => null, 'end' => null];
        }

        $shift = $shiftSchedule->shift;

        $shiftStart = $date->copy()->setTime(
            $shift->start_time->hour,
            $shift->start_time->minute,
            $shift->start_time->second
        );
        $shiftEnd = $date->copy()->setTime(
            $shift->end_time->hour,
            $shift->end_time->minute,
            $shift->end_time->second
        );

        // Handle midnight crossing (e.g., 22:00 - 02:00)
        if ($shiftEnd->lt($shiftStart)) {
            $shiftEnd->addDay();
        }

        return ['start' => $shiftStart, 'end' => $shiftEnd];
    }

    /**
     * Calculate total work duration from entrance time to present (or shift end if shift ended)
     *
     * @param User $user
     * @param Carbon $date
     * @param AttendanceGpsData|null $entranceGps First GPS point during shift
     * @return string|null Duration in "H:i" format or null if no entrance GPS
     */
    private function getTotalWorkDuration(User $user, Carbon $date, ?AttendanceGpsData $entranceGps): ?string
    {
        if (! $entranceGps) {
            return null;
        }

        $shiftRange = $this->getShiftTimeRange($user, $date);

        if (! $shiftRange['start'] || ! $shiftRange['end']) {
            return null;
        }

        $entranceDateTime = $entranceGps->date_time;

        // Use current time if within shift, otherwise use shift end
        $now = Carbon::now();
        $endTime = ($now->gte($shiftRange['start']) && $now->lt($shiftRange['end']))
            ? $now
            : $shiftRange['end'];

        // Calculate duration in minutes
        $durationMinutes = $entranceDateTime->diffInMinutes($endTime);

        // Convert to hours and minutes
        $hours = intval($durationMinutes / 60);
        $minutes = $durationMinutes % 60;

        return sprintf('%02d:%02d', $hours, $minutes);
    }

}
