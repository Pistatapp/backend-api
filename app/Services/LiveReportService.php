<?php

namespace App\Services;

use App\Models\GpsDevice;
use App\Traits\TractorWorkingTime;

class LiveReportService
{
    use TractorWorkingTime;

    private $dailyReport;
    private $latestStoredReport;
    private $tractor;
    private $currentTask;
    private $taskArea;

    public function __construct(
        private GpsDevice $device,
        private array $reports,
        private TractorTaskService $taskService,
        private DailyReportService $dailyReportService,
        private CacheService $cacheService,
        private ReportProcessingService $reportProcessingService
    ) {
        $this->initialize();
    }

    private function initialize(): void
    {
        $this->tractor = $this->device->tractor;
        $this->currentTask = $this->taskService->getCurrentTask();
        $this->taskArea = $this->taskService->getTaskArea($this->currentTask);
        $this->dailyReport = $this->dailyReportService->fetchOrCreate();
        $this->latestStoredReport = $this->cacheService->getLatestStoredReport();
    }

    public function generate(): array
    {
        $processedData = $this->reportProcessingService->process();

        $data = $this->dailyReportService->update($this->dailyReport, $processedData);

        return [
            'id' => $this->dailyReport->id,
            'tractor_id' => $this->tractor->id,
            'traveled_distance' => $data['traveled_distance'],
            'work_duration' => $data['work_duration'],
            'stoppage_duration' => $data['stoppage_duration'],
            'efficiency' => $data['efficiency'],
            'stoppage_count' => $data['stoppage_count'],
            'speed' => $processedData['latestStoredReport']->speed ?? 0,
            'points' => $processedData['points'],
        ];
    }
}
