<?php

namespace App\Services;

use App\Models\Labour;
use App\Models\Farm;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class ActiveLabourService
{
    /**
     * Get active labours for a farm (labours who sent GPS data in last 10 minutes)
     *
     * @param Farm $farm
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveLabours(Farm $farm)
    {
        $cacheKey = "active_labours_farm_{$farm->id}";
        
        return Cache::remember($cacheKey, now()->addMinutes(1), function () use ($farm) {
            $cutoffTime = Carbon::now()->subMinutes(10);
            
            return Labour::where('farm_id', $farm->id)
                ->whereHas('gpsData', function ($query) use ($cutoffTime) {
                    $query->where('date_time', '>=', $cutoffTime);
                })
                ->with(['gpsData' => function ($query) use ($cutoffTime) {
                    $query->where('date_time', '>=', $cutoffTime)
                        ->orderBy('date_time', 'desc')
                        ->limit(1);
                }])
                ->get()
                ->map(function ($labour) {
                    $latestGps = $labour->gpsData->first();
                    return [
                        'id' => $labour->id,
                        'name' => $labour->name,
                        'coordinate' => $latestGps?->coordinate,
                        'last_update' => $latestGps?->date_time,
                        'is_in_zone' => $this->isLabourInZone($labour, $latestGps?->coordinate),
                    ];
                });
        });
    }

    /**
     * Check if labour is currently in orchard zone
     *
     * @param Labour $labour
     * @param array|null $coordinate
     * @return bool
     */
    private function isLabourInZone(Labour $labour, ?array $coordinate): bool
    {
        if (!$coordinate) {
            return false;
        }

        $farm = $labour->farm;
        
        if (!$farm || !$farm->coordinates) {
            return false;
        }

        $point = [$coordinate['lng'], $coordinate['lat']];
        return is_point_in_polygon($point, $farm->coordinates);
    }

    /**
     * Clear active labours cache for a farm
     *
     * @param Farm $farm
     * @return void
     */
    public function clearCache(Farm $farm): void
    {
        Cache::forget("active_labours_farm_{$farm->id}");
    }
}

