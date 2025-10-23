<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Jobs\GPSReport\StoreGpsReportJob;
use App\Models\GpsReport;
use App\Services\GPSReport\GpsParserManager;
use App\Services\ParseDataService;
use App\Models\GpsDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GpsReportController extends Controller
{
    public function __construct(
        private ParseDataService          $parseDataService,
        private readonly GpsParserManager $gpsParserManager,
    )
    {
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function __invoke(Request $request)
    {
        try {
            $rawData = $request->getContent();

            // Parse the raw data using GpsParserManager (auto-detects device)
            $parsed = $this->gpsParserManager->parse($rawData);

            if (!$parsed) {
                Log::warning('Invalid or unrecognized GPS data received', [
                    'payload' => substr($rawData, 0, 120)
                ]);

                return response()->json(['message' => 'Invalid data'], 400);
            }

            // Queue or direct insert
            if (config('gps.use_queue')) {
                StoreGpsReportJob::dispatch($parsed);
            } else {
                foreach ($parsed as $record) {
                    GpsReport::create($record);
                }
            }


//            $rawData = $request->getContent();
//            $data = $this->parseDataService->parse($rawData);
//            $deviceImei = $data[0]['imei'];
//            // Log raw GPS data
//            $this->logRawGpsData($rawData, $deviceImei);
//            $device = $this->fetchDeviceByImei($deviceImei);
//            // Dispatch background job for processing
//            ProcessGpsReportsJob::dispatch($device, $data);
//            $lastReportStatus = end($data)['status'];
//            event(new TractorStatus($device->tractor, $lastReportStatus));

        } catch (\Exception $e) {
            $this->logErroredData($request);
//            return response()->json([
//                'status' => 'error',
//                'message' => $e->getMessage(),
//                'line' => $e->getLine(),
//                'file' => $e->getFile(),
//            ], 500);
        }

        return response()->json([], 200);
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

    /**
     * Log raw GPS data to a text file
     *
     * @param string $rawData Raw GPS data content
     * @param string $deviceImei Device IMEI number
     * @return void
     */
    private function logRawGpsData(string $rawData, string $deviceImei): void
    {
        $logDir = storage_path('logs');

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/gps_raw_data_' . $deviceImei . '_' . date('Y-m-d') . '.txt';
        $logEntry = date('Y-m-d H:i:s') . ' - ' . $rawData . PHP_EOL;

        file_put_contents($logFile, $logEntry . '-------------------' . PHP_EOL, FILE_APPEND);
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
