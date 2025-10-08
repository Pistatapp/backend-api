<?php

namespace App\Jobs;

use App\Models\GpsDevice;
use App\Services\ReportProcessingService;
use App\Services\GpsMetricsCalculationService;
use App\Services\TractorTaskService;
use App\Services\TractorTaskStatusService;
use App\Events\ReportReceived;
use App\Events\TractorZoneStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessGpsReportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private GpsDevice $device,
        private array $reports
    ) {}

    public function handle(): void
    {
        try {
            $currentTask = $this->getCurrentTask();
            $processedData = $this->processReports($currentTask);
            $metricsRecords = $this->updateGpsMetricsCalculations($currentTask, $processedData);
            $this->updateTaskStatus($currentTask, $processedData);
            $this->broadcastReportReceived($metricsRecords['dailyRecord'], $processedData);
            $this->broadcastZoneStatus($currentTask, $processedData);

        } catch (\Exception $e) {
            $this->handleJobFailure($e);
        }
    }

    /**
     * Get the current task and task zone for the tractor.
     * Uses the timestamp from the GPS reports to determine which task is active.
     *
     * @return array{task: \App\Models\TractorTask|null, zone: array|null}
     */
    private function getCurrentTask(): array
    {
        $taskService = new TractorTaskService($this->device->tractor);

        // Use the timestamp from the first report to determine current task
        $reportTime = !empty($this->reports) && isset($this->reports[0]['date_time'])
            ? $this->reports[0]['date_time']
            : now();

        $currentTask = $taskService->getCurrentTask($reportTime);
        $taskZone = $taskService->getTaskZone($currentTask);

        return [
            'task' => $currentTask,
            'zone' => $taskZone
        ];
    }

    /**
     * Process GPS reports and return processed data.
     *
     * @param array $taskData Current task and zone data
     * @return array Processed GPS data
     */
    private function processReports(array $taskData): array
    {
        $reportProcessor = new ReportProcessingService(
            $this->device,
            $this->reports,
            $taskData['task'],
            $taskData['zone']
        );

        return $reportProcessor->process();
    }

    /**
     * Update both task-specific and daily summary metrics calculations.
     *
     * @param array $taskData Current task and zone data
     * @param array $processedData Processed GPS data with taskData and dailyData
     * @return array{taskRecord: GpsMetricsCalculation|null, dailyRecord: GpsMetricsCalculation}
     */
    private function updateGpsMetricsCalculations(array $taskData, array $processedData): array
    {
        $metricsService = new GpsMetricsCalculationService($this->device->tractor, $taskData['task']);

        $taskRecord = null;
        $dailyRecord = null;

        // Update task-specific record if task data exists
        if ($processedData['taskData']) {
            $taskRecord = $metricsService->fetchOrCreate();
            $metricsService->update($taskRecord, $processedData['taskData']);
        }

        // Always update daily summary record
        $dailyRecord = $metricsService->fetchOrCreateDailyRecord();
        $metricsService->update($dailyRecord, $processedData['dailyData']);

        return [
            'taskRecord' => $taskRecord,
            'dailyRecord' => $dailyRecord
        ];
    }

    /**
     * Update the tractor task status based on GPS metrics and current conditions.
     *
     * @param array $taskData Current task and zone data
     * @param array $processedData Processed GPS data with taskData and dailyData
     * @return void
     */
    private function updateTaskStatus(array $taskData, array $processedData): void
    {
        $statusService = new TractorTaskStatusService();

        if ($taskData['task']) {
            // If tractor is in task zone, potentially mark task as in_progress
            $isInTaskZone = $this->isTractorInTaskZone($taskData, $processedData);
            if ($isInTaskZone) {
                $statusService->markTaskInProgressIfApplicable($taskData['task']);
            }

            // Always update task status based on current conditions
            $statusService->updateTaskStatusAfterGpsProcessing($taskData['task']);
        }

        // Also finalize any ended tasks for this tractor today
        // This handles cases where reports come in after task end time
        $statusService->finalizeEndedTasks(
            $this->device->tractor_id,
            today()->toDateString()
        );
    }

    /**
     * Broadcast the ReportReceived event with generated report data.
     *
     * @param \App\Models\GpsMetricsCalculation $dailyReport Updated daily report
     * @param array $processedData Processed GPS data with taskData and dailyData
     * @return void
     */
    private function broadcastReportReceived($dailyReport, array $processedData): void
    {
        // Use daily data for broadcasting (includes all working hours activity)
        $dailyData = $processedData['dailyData'];

        $generatedReport = [
            'id' => $dailyReport->id,
            'tractor_id' => $this->device->tractor->id,
            'traveled_distance' => $dailyData['totalTraveledDistance'],
            'work_duration' => $dailyData['totalMovingTime'],
            'stoppage_duration' => $dailyData['totalStoppedTime'],
            'efficiency' => $dailyReport->efficiency ?? 0,
            'stoppage_count' => $dailyData['stoppageCount'],
            'speed' => $processedData['latestStoredReport']->speed ?? 0,
            'points' => $dailyData['points'],
        ];

        event(new ReportReceived($generatedReport['points'], $this->device));
    }

    /**
     * Broadcast the TractorZoneStatus event with zone information.
     *
     * @param array $taskData Current task and zone data
     * @param array $processedData Processed GPS data with taskData and dailyData
     * @return void
     */
    private function broadcastZoneStatus(array $taskData, array $processedData): void
    {
        $isInTaskZone = $this->isTractorInTaskZone($taskData, $processedData);

        // Use task data for zone-specific work duration if available
        $workDurationInZone = null;
        if ($isInTaskZone && $processedData['taskData']) {
            $workDurationInZone = $this->formatWorkDuration($processedData['taskData']['totalMovingTime']);
        }

        // Derive a user-friendly task name using related operation/taskable
        $taskName = null;
        if ($taskData['task']) {
            $task = $taskData['task'];
            $task->loadMissing(['operation:id,name', 'taskable']);

            $operationName = $task->operation?->name;
            $taskableName = $task->taskable->name ?? null;

            if ($operationName && $taskableName) {
                $taskName = $operationName . ' - ' . $taskableName;
            } else {
                $taskName = $operationName ?? $taskableName ?? null;
            }
        }

        $zoneData = [
            'is_in_task_zone' => $isInTaskZone,
            'task_id' => $taskData['task']?->id,
            'task_name' => $taskName,
            'work_duration_in_zone' => $workDurationInZone,
        ];

        event(new TractorZoneStatus($zoneData, $this->device));
    }

    /**
     * Determine if tractor is currently in task zone based on GPS coordinates.
     *
     * @param array $taskData Current task and zone data
     * @param array $processedData Processed GPS data with taskData and dailyData
     * @return bool
     */
    private function isTractorInTaskZone(array $taskData, array $processedData): bool
    {
        // If no task or zone data, tractor is not in task zone
        if (!$taskData['task'] || !$taskData['zone']) {
            return false;
        }

        // Use daily data points for location determination (most comprehensive)
        $points = $processedData['dailyData']['points'] ?? [];

        // If no GPS points were processed, cannot determine location
        if (empty($points)) {
            return false;
        }

        // Get the latest GPS coordinate from processed points
        $latestPoint = end($points);
        $currentCoordinate = $latestPoint['coordinate'];

        // Check if current coordinate is within task zone polygon
        return is_point_in_polygon($currentCoordinate, $taskData['zone']);
    }

    /**
     * Format work duration in H:i:s format.
     *
     * @param int $seconds
     * @return string
     */
    private function formatWorkDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    }

    /**
     * Handle job failure by logging error and re-throwing exception.
     *
     * @param \Exception $exception The exception that caused the failure
     * @return void
     * @throws \Exception
     */
    private function handleJobFailure(\Exception $exception): void
    {
        Log::error('GPS processing job failed', [
            'device_id' => $this->device->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        throw $exception;
    }
}
