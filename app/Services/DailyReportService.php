<?php

namespace App\Services;

use App\Models\GpsDailyReport;
use App\Models\Tractor;
use App\Models\TractorTask;

class DailyReportService
{
    public function __construct(
        private Tractor $tractor,
        private ?TractorTask $currentTask
    ) {}

    /**
     * Fetch or create a daily report for the tractor.
     *
     * @return GpsDailyReport
     */
    public function fetchOrCreate(): GpsDailyReport
    {
        $taskId = $this->currentTask ? $this->currentTask->id : null;

        return GpsDailyReport::firstOrCreate([
            'tractor_id' => $this->tractor->id,
            'tractor_task_id' => $taskId,
            'date' => today()->toDateString()
        ]);
    }

    /**
     * Update the daily report with new data.
     *
     * @param GpsDailyReport $dailyReport
     * @param array $data
     * @return array
     */
    public function update(GpsDailyReport $dailyReport, array $data): array
    {
        $efficiency = $this->calculateEfficiency($data['totalMovingTime']);

        $updateData = [
            'traveled_distance' => $dailyReport->traveled_distance + $data['totalTraveledDistance'],
            'work_duration' => $dailyReport->work_duration + $data['totalMovingTime'],
            'stoppage_duration' => $dailyReport->stoppage_duration + $data['totalStoppedTime'],
            'efficiency' => $dailyReport->efficiency + $efficiency,
            'stoppage_count' => $dailyReport->stoppage_count + $data['stoppageCount'],
            'max_speed' => $data['maxSpeed'],
        ];

        // Calculate average speed with the new values
        $newWorkDuration = $updateData['work_duration'];
        $newTraveledDistance = $updateData['traveled_distance'];
        $updateData['average_speed'] = $newWorkDuration > 0
            ? $newTraveledDistance / ($newWorkDuration / 3600)
            : 0;

        $dailyReport->update($updateData);

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
}
