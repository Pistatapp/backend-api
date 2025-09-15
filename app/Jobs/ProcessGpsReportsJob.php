<?php

namespace App\Jobs;

use App\Models\GpsDevice;
use App\Services\ReportProcessingService;
use App\Services\GpsMetricsCalculationService;
use App\Services\TractorTaskService;
use App\Events\ReportReceived;
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
            $dailyReport = $this->updateDailyReport($currentTask, $processedData);
            $this->broadcastReportReceived($dailyReport, $processedData);

        } catch (\Exception $e) {
            $this->handleJobFailure($e);
        }
    }

    /**
     * Get the current task and task area for the tractor.
     *
     * @return array{task: \App\Models\TractorTask|null, area: array|null}
     */
    private function getCurrentTask(): array
    {
        $taskService = new TractorTaskService($this->device->tractor);
        $currentTask = $taskService->getCurrentTask();
        $taskArea = $taskService->getTaskArea($currentTask);

        return [
            'task' => $currentTask,
            'area' => $taskArea
        ];
    }

    /**
     * Process GPS reports and return processed data.
     *
     * @param array $taskData Current task and area data
     * @return array Processed GPS data
     */
    private function processReports(array $taskData): array
    {
        $reportProcessor = new ReportProcessingService(
            $this->device,
            $this->reports,
            $taskData['task'],
            $taskData['area']
        );

        return $reportProcessor->process();
    }

    /**
     * Update the daily report with processed data.
     *
     * @param array $taskData Current task and area data
     * @param array $processedData Processed GPS data
     * @return \App\Models\GpsMetricsCalculation Updated daily report
     */
    private function updateDailyReport(array $taskData, array $processedData)
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

        event(new ReportReceived($generatedReport, $this->device));
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
