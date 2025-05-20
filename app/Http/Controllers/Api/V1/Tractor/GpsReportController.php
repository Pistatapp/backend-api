<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Events\ReportReceived;
use App\Events\TractorStatus;
use App\Http\Controllers\Controller;
use App\Services\ParseDataService;
use App\Services\LiveReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\TractorTaskService;
use App\Services\DailyReportService;
use App\Services\CacheService;
use App\Services\ReportProcessingService;
use App\Repositories\GpsDeviceRepository;
class GpsReportController extends Controller
{
    public function __construct(
        private ParseDataService $parseDataService,
        private TractorTaskService $taskService,
        private DailyReportService $dailyReportService,
        private CacheService $cacheService,
        private GpsDeviceRepository $gpsDeviceRepository
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

            $deviceImei = $data[0]['imei'];

            $device = $this->gpsDeviceRepository->getByRelations($deviceImei, ['tractor']);

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
}
