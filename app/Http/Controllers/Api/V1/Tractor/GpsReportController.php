<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Events\TractorStatus;
use App\Http\Controllers\Controller;
use App\Services\ParseDataService;
use App\Jobs\ProcessGpsReportsJob;
use App\Models\GpsDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class GpsReportController extends Controller
{
    public function __construct(
        private ParseDataService $parseDataService,
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

            $device = $this->fetchDeviceByImei($deviceImei);

            // Dispatch background job for processing
            ProcessGpsReportsJob::dispatch($device, $data);

            $lastReportStatus = end($data)['status'];
            event(new TractorStatus($device->tractor, $lastReportStatus));

        } catch (\Exception $e) {
            $this->logErroredData($request);
        }

        return new JsonResponse([], JsonResponse::HTTP_OK);
    }

    /**
     * Fetch GPS device by IMEI with tractor relationship, using cache for performance.
     *
     * @param string $imei Device IMEI
     * @return GpsDevice|null
     */
    private function fetchDeviceByImei(string $imei): ?GpsDevice
    {
        $cacheKey = "gps_device_with_tractor_{$imei}";

        return Cache::remember($cacheKey, now()->addHour(), function () use ($imei) {
            return GpsDevice::where('imei', $imei)
                ->whereHas('tractor')
                ->with(['tractor'])
                ->first();
        });
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
