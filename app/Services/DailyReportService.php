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

    public function fetchOrCreate(): GpsDailyReport
    {
        $taskId = $this->currentTask ? $this->currentTask->id : null;

        return GpsDailyReport::firstOrCreate([
            'tractor_id' => $this->tractor->id,
            'tractor_task_id' => $taskId,
            'date' => today()
        ]);
    }

    public function update(GpsDailyReport $dailyReport, array $data): array
    {
        $efficiency = $this->calculateEfficiency($data['totalMovingTime']);
        $averageSpeed = $this->calculateAverageSpeed($dailyReport, $data['totalTraveledDistance']);

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

    private function calculateEfficiency(float $totalMovingTime): float
    {
        return $totalMovingTime / ($this->tractor->expected_daily_work_time * 3600) * 100;
    }

    private function calculateAverageSpeed(GpsDailyReport $dailyReport, float $totalTraveledDistance): float
    {
        return $dailyReport->work_duration > 0
            ? $dailyReport->traveled_distance / ($dailyReport->work_duration / 3600)
            : 0;
    }
}
