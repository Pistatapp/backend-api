<?php

namespace App\Services;

use App\Models\AttendanceSession;
use App\Models\Farm;
use App\Models\User;
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
            'profile',
            'attendanceTracking' => function ($query) use ($farm) {
                $query->where('farm_id', $farm->id);
            },
            'attendanceGpsData' => function ($query) use ($date) {
                $query->whereDate('date_time', $date)->orderBy('date_time', 'desc')->limit(1);
            },
            'shiftSchedules' => function ($query) use ($date) {
                $query->whereDate('scheduled_date', $date)->with('shift');
            },
            'attendanceSessions' => function ($query) use ($date) {
                $query->whereDate('date', $date)->limit(1);
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

        $attendanceSession = $user->attendanceSessions->first();
        $entryTime = $attendanceSession && $attendanceSession->entry_time
            ? $attendanceSession->entry_time->format('H:i:s')
            : '00:00:00';
        $workDuration = $attendanceSession ? $attendanceSession->in_zone_duration : 0;

        return [
            'id' => $user->id,
            'name' => $user->profile->name,
            'status' => $status,
            'entry_time' => $entryTime,
            'work_duration' => $workDuration,
            'image' => $user->profile->media_url,
        ];
    }

    /**
     * Check if user is currently in orchard zone
     *
     * @param User $user
     * @param Farm $farm
     * @param array|null $coordinate [(float)lat, (float)long]
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

        $lat = $coordinate[0];
        $lng = $coordinate[1];

        $point = [$lng, $lat];
        return is_point_in_polygon($point, $farm->coordinates);
    }

    /**
     * Calculate attendance status for a user based on work type, schedule and location.
     *
     * - Administrative: present/absent during start_work_time–end_work_time by farm boundary; resting outside.
     * - Shift-based: present/absent during scheduled shift by farm boundary; resting if no shift or outside shift.
     *
     * @param User $user
     * @param Farm $farm
     * @param Carbon $date
     * @param bool $isInZone
     * @return string|null
     */
    private function calculateStatus(User $user, Farm $farm, Carbon $date, bool $isInZone): ?string
    {
        $tracking = $user->attendanceTracking;

        if (! $tracking) {
            return null;
        }

        $workType = $tracking->work_type;
        $now = Carbon::now();

        if ($workType === 'administrative') {
            return $this->calculateStatusAdministrative($tracking, $date, $now, $isInZone);
        }

        if ($workType === 'shift_based') {
            return $this->calculateStatusShiftBased($user, $date, $now, $isInZone);
        }

        return null;
    }

    /**
     * Status for administrative work: within work time → present/absent by zone; outside → resting.
     *
     * @param \App\Models\AttendanceTracking $tracking
     * @param Carbon $date
     * @param Carbon $now
     * @param bool $isInZone
     * @return string
     */
    private function calculateStatusAdministrative($tracking, Carbon $date, Carbon $now, bool $isInZone): string
    {
        $startWorkTime = $tracking->start_work_time;
        $endWorkTime = $tracking->end_work_time;

        if (! $startWorkTime || ! $endWorkTime) {
            return 'resting';
        }

        $timeStr = fn ($t) => $t instanceof \DateTimeInterface ? $t->format('H:i:s') : (string) $t;
        $workStart = Carbon::parse($date->format('Y-m-d') . ' ' . $timeStr($startWorkTime));
        $workEnd = Carbon::parse($date->format('Y-m-d') . ' ' . $timeStr($endWorkTime));

        if ($workEnd->lte($workStart)) {
            $workEnd->addDay();
        }

        $isWithinWorkTime = $now->gte($workStart) && $now->lt($workEnd);

        if (! $isWithinWorkTime) {
            return 'resting';
        }

        return $isInZone ? 'present' : 'absent';
    }

    /**
     * Status for shift-based work: present/absent during scheduled shift by zone; no shift or outside shift → resting.
     *
     * @param User $user
     * @param Carbon $date
     * @param Carbon $now
     * @param bool $isInZone
     * @return string
     */
    private function calculateStatusShiftBased(User $user, Carbon $date, Carbon $now, bool $isInZone): string
    {
        $shiftSchedule = $user->shiftSchedules->first();

        if (! $shiftSchedule || ! $shiftSchedule->shift) {
            return 'resting';
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

        if ($shiftEnd->lt($shiftStart)) {
            $shiftEnd->addDay();
        }

        $isWithinShift = $now->gte($shiftStart) && $now->lt($shiftEnd);

        if (! $isWithinShift) {
            return 'resting';
        }

        return $isInZone ? 'present' : 'absent';
    }

    /**
     * Get performance data for the user's attendance session(s) on the specified date.
     * Farm is the user's current working environment; when null, returns empty collection.
     *
     * @param User $user
     * @param Farm $farm
     * @param Carbon $date
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function getPerformance(User $user, Farm $farm, Carbon $date)
    {
        $tracking = $user->attendanceTracking()->where('farm_id', $farm->id)->first();

        if (! $tracking) {
            return response()->json([
                'success' => false,
                'message' => __('User does not have attendance tracking enabled for this farm.'),
            ], 404);
        }

        $attendanceSession = $user->attendanceSessions()->whereDate('date', $date)->first();

        return $this->buildPerformanceDetails($attendanceSession);
    }

    /**
     * Build a single performance row from an attendance session.
     *
     * @param AttendanceSession|null $session
     * @return array<string, mixed>
     */
    private function buildPerformanceDetails(?AttendanceSession $session): array
    {
        $user = $session->user;

        if (! $session) {
            return [
                'id' => $user->id,
                'name' => $user->profile->name,
                'image' => $user->profile->media_url,
                'entry_time' => '00:00:00',
                'exit_time' => '00:00:00',
                'required_work_duration' => 0,
                'extra_work_duration' => 0,
                'outside_zone_duration' => 0,
                'efficiency' => 0,
                'task_based_efficiency' => 0,
            ];
        }

        return [
            'id' => $user->id,
            'name' => $user->profile->name,
            'image' => $user->profile->media_url,
            'entry_time' => $session->entry_time?->format('H:i:s') ?? '00:00:00',
            'exit_time' => $session->exit_time?->format('H:i:s') ?? '00:00:00',
            'required_work_duration' => 0,
            'extra_work_duration' => 0,
            'outside_zone_duration' => $session->outside_zone_duration,
            'efficiency' => $session->efficiency,
            'task_based_efficiency' => 0,
        ];
    }

}
