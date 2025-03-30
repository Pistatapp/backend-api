<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Events\ReportReceived;
use App\Events\TractorStatus;
use App\Http\Controllers\Controller;
use App\Services\ParseDataService;
use App\Services\LiveReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\GpsDevice;
use App\Services\TractorTaskService;
use App\Services\DailyReportService;
use App\Services\CacheService;
use App\Services\ReportProcessingService;

class GpsReportController extends Controller
{
    public function __construct(
        private ParseDataService $parseDataService,
        private TractorTaskService $taskService,
        private DailyReportService $dailyReportService,
        private CacheService $cacheService
    ) {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function __invoke(Request $request)
    {
        try {
            $data = $this->parseDataService->parse($request->getContent());

            $device = $this->getDevice($data[0]['imei']);

            if(!$device) {
                return new JsonResponse(['message' => 'Device not found'], JsonResponse::HTTP_NOT_FOUND);
            }

            $lastReportStatus = end($data)['status'];

            $taskService = new TractorTaskService($device->tractor);
            $currentTask = $taskService->getCurrentTask();
            $taskArea = $taskService->getTaskArea($currentTask);
            $dailyReportService = new DailyReportService($device->tractor, $currentTask);
            $reportService = new LiveReportService(
                $device,
                $data,
                $taskService,
                $dailyReportService,
                $this->cacheService,
                new ReportProcessingService(
                    $device,
                    $data,
                    $currentTask,
                    $taskArea,
                    fn($report) => true,
                    $this->cacheService
                )
            );
            $generatedReport = $reportService->generate();

            event(new ReportReceived($generatedReport, $device));
            event(new TractorStatus($device->tractor, $lastReportStatus));

        } catch (\Exception $e) {
            //
        }

        return new JsonResponse([], JsonResponse::HTTP_OK);
    }

    /**
     * Get device by imei
     *
     * @param string $imei
     * @return GPSDevice|null
     */
    private function getDevice(string $imei): ?GpsDevice
    {
        return Cache::remember('gps_device_' . $imei, 3600, function () use ($imei) {
            return GpsDevice::where('imei', $imei)
                ->whereHas('tractor')
                ->with('tractor')
                ->first();
        });
    }
}
