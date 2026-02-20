<?php

namespace App\Services;

use App\Models\User;
use App\Models\Farm;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class ActiveUserAttendanceService
{
    /**
     * Get active users with attendance tracking for a farm (users who sent GPS data in last 10 minutes)
     *
     * @param Farm $farm
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveUsers(Farm $farm)
    {
        $cacheKey = "active_attendance_users_farm_{$farm->id}";

        return Cache::remember($cacheKey, now()->addMinutes(1), function () use ($farm) {
            $cutoffTime = Carbon::now()->subMinutes(10);

            return User::whereHas('attendanceTracking', function ($query) use ($farm) {
                $query->where('farm_id', $farm->id)->where('enabled', true);
            })->with(['profile:id,user_id,name', 'attendanceGpsData' => function ($query) use ($cutoffTime) {
                $query->where('date_time', '>=', $cutoffTime)
                    ->orderBy('date_time', 'desc')
                    ->limit(1);
            }])->get()
                ->map(function ($user) use ($farm) {
                    $latestGps = $user->attendanceGpsData->first();
                    return [
                        'id' => $user->id,
                        'name' => $user->profile->name,
                        'coordinate' => $latestGps?->coordinate,
                        'last_update' => $latestGps?->date_time,
                        'is_in_zone' => $this->isUserInZone($user, $farm, $latestGps?->coordinate),
                    ];
                });
        });
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
     * Clear active users cache for a farm
     *
     * @param Farm $farm
     * @return void
     */
    public function clearCache(Farm $farm): void
    {
        Cache::forget("active_attendance_users_farm_{$farm->id}");
    }
}
