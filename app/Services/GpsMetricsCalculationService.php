<?php

namespace App\Services;

use App\Models\GpsMetricsCalculation;
use App\Models\Tractor;
use App\Models\TractorTask;

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
     * Update both task-specific and daily summary metrics calculations.
     *
     * @param array $data Processed GPS data
     * @return array{taskRecord: GpsMetricsCalculation, dailyRecord: GpsMetricsCalculation}
     */
    public function updateBothRecords(array $data): array
    {
        $taskRecord = null;
        $dailyRecord = null;

        // Update task-specific record if there's a current task
        if ($this->currentTask) {
            $taskRecord = $this->fetchOrCreate();
            $this->update($taskRecord, $data);
        }

        // Always update/create daily summary record (tractor_task_id = null)
        $dailyRecord = $this->fetchOrCreateDailyRecord();
        $this->update($dailyRecord, $data);

        return [
            'taskRecord' => $taskRecord,
            'dailyRecord' => $dailyRecord
        ];
    }

    /**
     * Fetch or create a daily summary metrics calculation (no task association).
     *
     * @return GpsMetricsCalculation
     */
    public function fetchOrCreateDailyRecord(): GpsMetricsCalculation
    {
        return GpsMetricsCalculation::firstOrCreate([
            'tractor_id' => $this->tractor->id,
            'tractor_task_id' => null,
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
        ];

        // Calculate average speed with the new values
        $newWorkDuration = $updateData['work_duration'];
        $newTraveledDistance = $updateData['traveled_distance'];
        $updateData['average_speed'] = $newWorkDuration > 0
            ? $newTraveledDistance / ($newWorkDuration / 3600)
            : 0;

        $metricsCalculation->update($updateData);

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
        // Always use tractor's expected daily work time for efficiency calculation
        return $totalMovingTime / ($this->tractor->expected_daily_work_time * 3600) * 100;
    }
}
