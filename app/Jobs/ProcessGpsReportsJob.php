<?php

namespace App\Jobs;

use App\Models\GpsDevice;
use App\Services\ReportProcessingService;
use App\Services\GpsMetricsCalculationService;
use App\Services\TractorTaskService;
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
            $dailyReport = $this->updateGpsMetricsCalculations($currentTask, $processedData);
            $this->broadcastReportReceived($dailyReport, $processedData);
            $this->broadcastZoneStatus($currentTask, $processedData);

        } catch (\Exception $e) {
            $this->handleJobFailure($e);
        }
    }

    /**
     * Get the current task and task zone for the tractor.
     *
     * @return array{task: \App\Models\TractorTask|null, zone: array|null}
     */
    private function getCurrentTask(): array
    {
        $taskService = new TractorTaskService($this->device->tractor);
        $currentTask = $taskService->getCurrentTask();
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
     * Update the daily report with processed data.
     *
     * @param array $taskData Current task and zone data
     * @param array $processedData Processed GPS data
     * @return \App\Models\GpsMetricsCalculation Updated daily report
     */
    private function updateGpsMetricsCalculations(array $taskData, array $processedData)
    {
        $dailyReportService = new GpsMetricsCalculationService($this->device->tractor, $taskData['task']);
        $dailyReport = $dailyReportService->fetchOrCreate();
        $dailyReportService->update($dailyReport, $processedData);

        return $dailyReport;
    }

    /**
     * Broadcast the ReportReceived event with generated report data.
     *
     * @param \App\Models\GpsMetricsCalculation $dailyReport Updated daily report
     * @param array $processedData Processed GPS data
     * @return void
     */
    private function broadcastReportReceived($dailyReport, array $processedData): void
    {
        $generatedReport = [
            'id' => $dailyReport->id,
            'tractor_id' => $this->device->tractor->id,
            'traveled_distance' => $processedData['totalTraveledDistance'],
            'work_duration' => $processedData['totalMovingTime'],
            'stoppage_duration' => $processedData['totalStoppedTime'],
            'efficiency' => $dailyReport->efficiency ?? 0,
            'stoppage_count' => $processedData['stoppageCount'],
            'speed' => $processedData['latestStoredReport']->speed ?? 0,
            'points' => $processedData['points'],
        ];

        event(new ReportReceived($generatedReport['points'], $this->device));
    }

    /**
     * Broadcast the TractorZoneStatus event with zone information.
     *
     * @param array $taskData Current task and zone data
     * @param array $processedData Processed GPS data
     * @return void
     */
    private function broadcastZoneStatus(array $taskData, array $processedData): void
    {
        $isInTaskZone = $this->isTractorInTaskZone($taskData, $processedData);

        $zoneData = [
            'is_in_task_zone' => $isInTaskZone,
            'task_id' => $taskData['task']?->id,
            'task_name' => $taskData['task']?->name,
            'work_duration_in_zone' => $isInTaskZone ? $this->formatWorkDuration($processedData['totalMovingTime']) : null,
        ];

        event(new TractorZoneStatus($zoneData, $this->device));
    }

    /**
     * Determine if tractor is currently in task zone based on GPS coordinates.
     *
     * @param array $taskData Current task and zone data
     * @param array $processedData Processed GPS data
     * @return bool
     */
    private function isTractorInTaskZone(array $taskData, array $processedData): bool
    {
        // If no task or zone data, tractor is not in task zone
        if (!$taskData['task'] || !$taskData['zone']) {
            return false;
        }

        // If no GPS points were processed, cannot determine location
        if (empty($processedData['points'])) {
            return false;
        }

        // Get the latest GPS coordinate from processed points
        $latestPoint = end($processedData['points']);
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
