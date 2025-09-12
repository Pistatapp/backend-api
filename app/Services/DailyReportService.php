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
        $averageSpeed = $this->calculateAverageSpeed($dailyReport);

        $updateData = [
            'traveled_distance' => $dailyReport->traveled_distance + $data['totalTraveledDistance'],
            'work_duration' => $dailyReport->work_duration + $data['totalMovingTime'],
            'stoppage_duration' => $dailyReport->stoppage_duration + $data['totalStoppedTime'],
            'efficiency' => $dailyReport->efficiency + $efficiency,
            'stoppage_count' => $dailyReport->stoppage_count + $data['stoppageCount'],
            'max_speed' => $data['maxSpeed'],
            'average_speed' => $averageSpeed,
        ];

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

    /**
     * Calculate the average speed of the tractor.
     *
     * @param GpsDailyReport $dailyReport
     * @return int
     */
    private function calculateAverageSpeed(GpsDailyReport $dailyReport): float
    {
        $averageSpeed = $dailyReport->work_duration > 0
            ? $dailyReport->traveled_distance / ($dailyReport->work_duration / 3600)
            : 0;

        return $averageSpeed;
    }
}
