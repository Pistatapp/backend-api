<?php

namespace App\Services;

use App\Models\Labour;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class LabourPathService
{
    /**
     * Retrieves the labour movement path for a specific date using GPS data.
     *
     * @param Labour $labour
     * @param Carbon $date
     * @return \Illuminate\Support\Collection
     */
    public function getLabourPath(Labour $labour, Carbon $date)
    {
        try {
            // Check if labour has GPS data for this date
            $hasData = $labour->gpsData()
                ->whereDate('date_time', $date)
                ->limit(1)
                ->exists();

            if (!$hasData) {
                return collect();
            }

            // Get GPS data for the date, ordered by time
            $gpsData = $labour->gpsData()
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
            Log::error('Failed to get labour path', [
                'labour_id' => $labour->id,
                'date' => $date->toDateString(),
                'error' => $e->getMessage()
            ]);

            return collect();
        }
    }

    /**
     * Get the latest GPS point for a labour
     *
     * @param Labour $labour
     * @return array|null
     */
    public function getLatestPoint(Labour $labour): ?array
    {
        $latestGps = $labour->gpsData()
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

