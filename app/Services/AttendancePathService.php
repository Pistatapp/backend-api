<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;

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
        $gpsData = $user->attendanceGpsData()
            ->whereDate('date_time', $date)
            ->orderBy('date_time')
            ->get();

        if ($gpsData->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $gpsData = $gpsData->map(function ($point) {
            return [
                'id' => $point->id,
                'coordinate' => $point->coordinate,
                'speed' => $point->speed,
                'time' => $point->date_time->format('H:i:s'),
            ];
        });

        return response()->json(['data' => $gpsData]);
    }
}
