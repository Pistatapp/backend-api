<?php

namespace App\Services;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class WorkerPathService
{
    /**
     * Retrieves the worker movement path for a specific date using GPS data.
     *
     * @param Employee $employee
     * @param Carbon $date
     * @return \Illuminate\Support\Collection
     */
    public function getWorkerPath(Employee $employee, Carbon $date)
    {
        try {
            // Check if worker has GPS data for this date
            $hasData = $employee->gpsData()
                ->whereDate('date_time', $date)
                ->limit(1)
                ->exists();

            if (!$hasData) {
                return collect();
            }

            // Get GPS data for the date, ordered by time
            $gpsData = $employee->gpsData()
                ->whereDate('date_time', $date)
                ->orderBy('date_time')
                ->get();

            // Format points for map display
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
            Log::error('Failed to get worker path', [
                'employee_id' => $employee->id,
                'date' => $date->toDateString(),
                'error' => $e->getMessage()
            ]);

            return collect();
        }
    }

    /**
     * Get the latest GPS point for a worker
     *
     * @param Employee $employee
     * @return array|null
     */
    public function getLatestPoint(Employee $employee): ?array
    {
        $latestGps = $employee->gpsData()
            ->orderBy('date_time', 'desc')
            ->first();

        if (!$latestGps) {
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

