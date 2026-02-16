<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AttendancePathService
{
    /**
     * Get user movement path for a specific date using GPS data.
     *
     * @param User $user
     * @param Carbon $date
     * @return \Illuminate\Support\Collection
     */
    public function getUserPath(User $user, Carbon $date)
    {
        try {
            $hasData = $user->attendanceGpsData()
                ->whereDate('date_time', $date)
                ->limit(1)
                ->exists();

            if (! $hasData) {
                return collect();
            }

            $gpsData = $user->attendanceGpsData()
                ->whereDate('date_time', $date)
                ->orderBy('date_time')
                ->get();

            return $gpsData->map(function ($point) {
                return [
                    'id' => $point->id,
                    'coordinate' => $point->coordinate,
                    'speed' => $point->speed,
                    'bearing' => $point->bearing,
                    'accuracy' => $point->accuracy,
                    'provider' => $point->provider,
                    'date_time' => $point->date_time->toIso8601String(),
                ];
            });
        } catch (\Exception $e) {
            Log::error('Failed to get user attendance path', [
                'user_id' => $user->id,
                'date' => $date->toDateString(),
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Get the latest GPS point for a user
     *
     * @param User $user
     * @return array|null
     */
    public function getLatestPoint(User $user): ?array
    {
        $latestGps = $user->attendanceGpsData()
            ->orderBy('date_time', 'desc')
            ->first();

        if (! $latestGps) {
            return null;
        }

        return [
            'id' => $latestGps->id,
            'coordinate' => $latestGps->coordinate,
            'speed' => $latestGps->speed,
            'bearing' => $latestGps->bearing,
            'accuracy' => $latestGps->accuracy,
            'provider' => $latestGps->provider,
            'date_time' => $latestGps->date_time->toIso8601String(),
        ];
    }
}
