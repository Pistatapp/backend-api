<?php

namespace App\Services;

use App\Models\GpsDevice;

class LiveReportService
{
    private $dailyReport;
    private $tractor;

    public function __construct(
        private GpsDevice $device,
        private array $reports,
        private TractorTaskService $taskService,
        private DailyReportService $dailyReportService,
        private CacheService $cacheService,
        private ReportProcessingService $reportProcessingService
    ) {
        $this->dailyReport = $this->dailyReportService->fetchOrCreate();
        $this->tractor = $this->device->tractor;
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
