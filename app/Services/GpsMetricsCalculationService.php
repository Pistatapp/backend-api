<?php

namespace App\Services;

use App\Models\GpsMetricsCalculation;
use App\Models\Tractor;
use App\Models\TractorTask;
use App\Services\CacheService;
use Illuminate\Support\Facades\Log;
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
            'max_speed' => $data['maxSpeed'],
        ];

        // Calculate average speed with the new values
        $newWorkDuration = $updateData['work_duration'];
        $newTraveledDistance = $updateData['traveled_distance'];
        $updateData['average_speed'] = $newWorkDuration > 0
            ? $newTraveledDistance / ($newWorkDuration / 3600)
            : 0;

        $metricsCalculation->update($updateData);

        // Validate metrics after update
        $this->validateMetrics($metricsCalculation);

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
        // Always use tractor's expected daily work time for efficiency calculation
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

    /**
     * Validate metrics to ensure they meet business constraints.
     *
     * @param GpsMetricsCalculation $metrics
     * @return void
     */
    private function validateMetrics(GpsMetricsCalculation $metrics): void
    {
        // Validate that total time doesn't exceed configured work hours
        if (!$this->validateTimeConstraint($metrics)) {
            Log::warning('GPS metrics validation failed: Time constraint exceeded', [
                'tractor_id' => $this->tractor->id,
                'date' => $metrics->date,
                'work_duration' => $metrics->work_duration,
                'stoppage_duration' => $metrics->stoppage_duration,
                'total' => $metrics->work_duration + $metrics->stoppage_duration,
                'max_allowed' => $this->getMaxWorkTimeInSeconds(),
            ]);
        }

        // Validate stoppage count matches persisted reports (only for task-specific records)
        if ($metrics->tractor_task_id !== null) {
            if (!$this->verifyStoppageCount($metrics)) {
                Log::warning('GPS metrics validation failed: Stoppage count mismatch', [
                    'tractor_id' => $this->tractor->id,
                    'task_id' => $metrics->tractor_task_id,
                    'date' => $metrics->date,
                    'metrics_stoppage_count' => $metrics->stoppage_count,
                ]);
            }
        }
    }

    /**
     * Validate that work_duration + stoppage_duration doesn't exceed configured work hours.
     *
     * @param GpsMetricsCalculation $metrics
     * @return bool
     */
    private function validateTimeConstraint(GpsMetricsCalculation $metrics): bool
    {
        $totalTime = $metrics->work_duration + $metrics->stoppage_duration;
        $maxWorkTime = $this->getMaxWorkTimeInSeconds();

        return $totalTime <= $maxWorkTime;
    }

    /**
     * Get the maximum work time in seconds based on tractor configuration.
     *
     * @return int
     */
    private function getMaxWorkTimeInSeconds(): int
    {
        $startTime = \Carbon\Carbon::parse($this->tractor->start_work_time);
        $endTime = \Carbon\Carbon::parse($this->tractor->end_work_time);

        // Handle case where end time is before start time (crosses midnight)
        if ($endTime->lt($startTime)) {
            $endTime->addDay();
        }

        return $startTime->diffInSeconds($endTime);
    }

    /**
     * Verify that stoppage count in metrics matches persisted stopped reports.
     *
     * @param GpsMetricsCalculation $metrics
     * @return bool
     */
    private function verifyStoppageCount(GpsMetricsCalculation $metrics): bool
    {
        $device = $this->tractor->gpsDevice;
        if (!$device) {
            return true; // Can't verify without device
        }

        // Count persisted stopped reports for this date
        $persistedCount = $device->reports()
            ->whereDate('date_time', $metrics->date)
            ->where('is_stopped', true)
            ->where('stoppage_time', '>', 60) // Only count stoppages > 60s
            ->count();

        return $metrics->stoppage_count == $persistedCount;
    }
}
