<?php

namespace App\Services;

use App\Models\GpsMetricsCalculation;
use App\Models\Tractor;
use App\Models\TractorTask;

use App\Services\CacheService;
class GpsMetricsCalculationService
{
    public function __construct(
        private Tractor $tractor,
        private ?TractorTask $currentTask
    ) {}

    /**
     * Fetch or create a metrics calculation for the tractor.
     *
     * @return GpsMetricsCalculation
     */
    public function fetchOrCreate(): GpsMetricsCalculation
    {
        $taskId = $this->currentTask ? $this->currentTask->id : null;

        return GpsMetricsCalculation::firstOrCreate([
            'tractor_id' => $this->tractor->id,
            'tractor_task_id' => $taskId,
            'date' => today()->toDateString()
        ]);
    }

    /**
     * Update the metrics calculation with new data.
     *
     * @param GpsMetricsCalculation $metricsCalculation
     * @param array $data
     * @return array
     */
    public function update(GpsMetricsCalculation $metricsCalculation, array $data): array
    {
        $efficiency = $this->calculateEfficiency($data['totalMovingTime']);

        $updateData = [
            'traveled_distance' => $metricsCalculation->traveled_distance + $data['totalTraveledDistance'],
            'work_duration' => $metricsCalculation->work_duration + $data['totalMovingTime'],
            'stoppage_duration' => $metricsCalculation->stoppage_duration + $data['totalStoppedTime'],
            'efficiency' => $metricsCalculation->efficiency + $efficiency,
            'stoppage_count' => $metricsCalculation->stoppage_count + $data['stoppageCount'],
            'max_speed' => $data['maxSpeed'],
        ];

        // Calculate average speed with the new values
        $newWorkDuration = $updateData['work_duration'];
        $newTraveledDistance = $updateData['traveled_distance'];
        $updateData['average_speed'] = $newWorkDuration > 0
            ? $newTraveledDistance / ($newWorkDuration / 3600)
            : 0;

        $metricsCalculation->update($updateData);

        // Invalidate cache for the updated date
        $this->invalidateCache($metricsCalculation->date);

        return $updateData;
    }

    /**
     * Calculate the efficiency of the tractor.
     *
     * @param float $totalMovingTime
     * @return float
     */
    private function calculateEfficiency(float $totalMovingTime): float
    {
        return $totalMovingTime / ($this->tractor->expected_daily_work_time * 3600) * 100;
    }

    /**
     * Invalidate cache for the given date.
     *
     * @param string $date
     * @return void
     */
    private function invalidateCache(string $date): void
    {
        $device = $this->tractor->gpsDevice;
        if ($device) {
            $cacheService = new CacheService($device);
            $cacheService->invalidateCache($date);
        }
    }
}
