<?php

namespace App\Services;

use App\Models\GpsDevice;
use Illuminate\Support\Facades\Log;

class LiveReportService
{
    private $dailyReport;
    private $tractor;

    public function __construct(
        private TractorTaskService $taskService,
        private DailyReportService $dailyReportService,
        private CacheService $cacheService,
    ) {
    }

    /**
     * Generate the live report based on the provided GPS device and data.
     *
     * @param GpsDevice $device
     * @param array $data
     * @return array
     */
    public function generate(GpsDevice $device, array $data): array
    {
        $this->initializeTractor($device);
        $processedData = $this->processReportData($device, $data);
        $updatedData = $this->updateDailyReport($processedData);

        return $this->prepareResponse($updatedData);
    }

    /**
     * Initialize the tractor property using the GPS device.
     *
     * @param GpsDevice $device
     * @return void
     */
    private function initializeTractor(GpsDevice $device): void
    {
        $this->tractor = $device->tractor;
    }

    /**
     * Process the report data using the ReportProcessingService.
     *
     * @param GpsDevice $device
     * @param array $data
     * @return array
     */
    private function processReportData(GpsDevice $device, array $data): array
    {
        // Set the tractor in TractorTaskService
        $this->taskService = new TractorTaskService($this->tractor);
        $currentTask = $this->taskService->getCurrentTask();
        $taskArea = $this->taskService->getTaskArea($currentTask);

        // Set the tractor and task in DailyReportService
        $this->dailyReportService = new DailyReportService($this->tractor, $currentTask);
        $this->dailyReport = $this->dailyReportService->fetchOrCreate();

        Log::info('Daily report', $this->dailyReport->toArray());

        $reportProcessingService = new ReportProcessingService(
            $device,
            $data,
            $currentTask,
            $taskArea,
        );

        return $reportProcessingService->process();
    }

    /**
     * Update the daily report with processed data.
     *
     * @param array $processedData
     * @return array
     */
    private function updateDailyReport(array $processedData): array
    {
        Log::info('Updating daily report', $processedData);
        $data = $this->dailyReportService->update($this->dailyReport, $processedData);

        $data['points'] = $processedData['points'];
        $data['latestStoredReport'] = $processedData['latestStoredReport'];

        return $data;
    }

    /**
     * Prepare the response data for the live report.
     *
     * @param array $data
     * @return array
     */
    private function prepareResponse(array $data): array
    {
        return [
            'id' => $this->dailyReport->id,
            'tractor_id' => $this->tractor->id,
            'traveled_distance' => $data['traveled_distance'],
            'work_duration' => $data['work_duration'],
            'stoppage_duration' => $data['stoppage_duration'],
            'efficiency' => $data['efficiency'],
            'stoppage_count' => $data['stoppage_count'],
            'speed' => $data['latestStoredReport']->speed ?? 0,
            'points' => $data['points'],
        ];
    }
}
