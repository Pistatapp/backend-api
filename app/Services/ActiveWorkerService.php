<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Farm;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class ActiveWorkerService
{
    /**
     * Get active workers for a farm (workers who sent GPS data in last 10 minutes)
     *
     * @param Farm $farm
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveWorkers(Farm $farm)
    {
        $cacheKey = "active_workers_farm_{$farm->id}";
        
        return Cache::remember($cacheKey, now()->addMinutes(1), function () use ($farm) {
            $cutoffTime = Carbon::now()->subMinutes(10);
            
            return Employee::where('farm_id', $farm->id)
                ->whereHas('gpsData', function ($query) use ($cutoffTime) {
                    $query->where('date_time', '>=', $cutoffTime);
                })
                ->with(['gpsData' => function ($query) use ($cutoffTime) {
                    $query->where('date_time', '>=', $cutoffTime)
                        ->orderBy('date_time', 'desc')
                        ->limit(1);
                }])
                ->get()
                ->map(function ($employee) {
                    $latestGps = $employee->gpsData->first();
                    return [
                        'id' => $employee->id,
                        'name' => $employee->full_name,
                        'fname' => $employee->fname,
                        'lname' => $employee->lname,
                        'coordinate' => $latestGps?->coordinate,
                        'last_update' => $latestGps?->date_time,
                        'is_in_zone' => $this->isWorkerInZone($employee, $latestGps?->coordinate),
                    ];
                });
        });
    }

    /**
     * Check if worker is currently in orchard zone
     *
     * @param Employee $employee
     * @param array|null $coordinate
     * @return bool
     */
    private function isWorkerInZone(Employee $employee, ?array $coordinate): bool
    {
        if (!$coordinate) {
            return false;
        }

        $farm = $employee->farm;
        
        if (!$farm || !$farm->coordinates) {
            return false;
        }

        $point = [$coordinate['lng'], $coordinate['lat']];
        return is_point_in_polygon($point, $farm->coordinates);
    }

    /**
     * Clear active workers cache for a farm
     *
     * @param Farm $farm
     * @return void
     */
    public function clearCache(Farm $farm): void
    {
        Cache::forget("active_workers_farm_{$farm->id}");
    }
}

