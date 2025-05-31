<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Events\ReportReceived;
use App\Events\TractorStatus;
use App\Http\Controllers\Controller;
use App\Services\ParseDataService;
use App\Services\LiveReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Repositories\GpsDeviceRepository;
use Illuminate\Support\Facades\Log;

class GpsReportController extends Controller
{
    public function __construct(
        private ParseDataService $parseDataService,
        private GpsDeviceRepository $gpsDeviceRepository,
        private LiveReportService $liveReportService,
    ) {}

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function __invoke(Request $request)
    {
        try {
            $rawData = $request->getContent();
            $data = $this->parseDataService->parse($rawData);

            $deviceImei = $data[0]['imei'];

            $device = $this->gpsDeviceRepository->findByRelations($deviceImei, ['tractor']);

            throw_if(!$device, \Exception::class, 'Device not found for IMEI: ' . $deviceImei);

            $lastReportStatus = end($data)['status'];

            $generatedReport = $this->liveReportService->generate($device, $data);

            event(new ReportReceived($generatedReport, $device));
            event(new TractorStatus($device->tractor, $lastReportStatus));
        } catch (\Exception $e) {
            $this->logErroredData($request);
            Log::error('GpsReportController error: ' . $e->getMessage());
        }

        return new JsonResponse([], JsonResponse::HTTP_OK);
    }

    private function logErroredData($request): void
    {
        $logDir = storage_path('logs/gps-data');

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/' . date('Y-m-d') . '.log';
        $errorMessage = date('Y-m-d H:i:s') . ' - Error: ' . $request->getContent() . PHP_EOL;

        file_put_contents($logFile, $errorMessage . '-------------------' . PHP_EOL, FILE_APPEND);
    }
}
