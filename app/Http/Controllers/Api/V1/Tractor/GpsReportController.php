<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Services\ParseDataService;
use App\Models\GpsData;
use App\Models\Tractor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Events\TractorStatus;
use App\Events\ReportReceived;

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

            // Log raw GPS data
            $this->logRawGpsData($rawData, $deviceImei);

            $tractor = $this->fetchTractorByDeviceImei($deviceImei);

            if (!$tractor) {
                return response()->json([], 200);
            }

            // Save parsed GPS data to database
            $this->saveGpsData($data, $tractor->id);

            // Update tractor status
            $lastStatus = end($data)['status'];
            event(new TractorStatus($tractor, $lastStatus));

            // Get device for ReportReceived event (only fire if device exists)
            $device = $tractor->gpsDevice;
            event(new ReportReceived($data, $device));
        } catch (\Exception $e) {
            // Log error
        }

        return response()->json([], 200);
    }

    /**
     * Fetch tractor by device IMEI, using cache for performance.
     *
     * @param string $imei Device IMEI
     * @return Tractor|null
     */
    private function fetchTractorByDeviceImei(string $imei): ?Tractor
    {
        $cacheKey = "tractor_by_device_imei_{$imei}";

        return Cache::remember($cacheKey, now()->addHour(), function () use ($imei) {
            $tractor = Tractor::whereHas('gpsDevice', function ($query) use ($imei) {
                $query->where('imei', $imei);
            })->with('gpsDevice')->first();

            return $tractor;
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
        $baseLogDir = storage_path('logs');
        $imeiDir = $baseLogDir . '/' . $deviceImei;

        // Create logs directory if it doesn't exist
        if (!is_dir($baseLogDir)) {
            mkdir($baseLogDir, 0755, true);
        }

        // Create IMEI-specific directory if it doesn't exist
        if (!is_dir($imeiDir)) {
            mkdir($imeiDir, 0755, true);
        }

        $logFile = $imeiDir . '/gps_raw_data_' . date('Y-m-d') . '.txt';
        $logEntry = date('Y-m-d H:i:s') . ' - ' . $rawData . PHP_EOL;

        file_put_contents($logFile, $logEntry . '-------------------' . PHP_EOL, FILE_APPEND);
    }

    /**
     * Save parsed GPS data to database
     *
     * @param array $data Parsed GPS data array
     * @param int $tractorId Tractor ID
     * @return void
     */
    private function saveGpsData(array $data, int $tractorId): void
    {
        if (empty($data)) {
            return;
        }

        $batchSize = 500; // Process in batches of 500 records
        $batches = array_chunk($data, $batchSize);

        foreach ($batches as $batch) {
            $gpsDataRecords = [];

            foreach ($batch as $item) {
                $gpsDataRecords[] = [
                    'tractor_id' => $tractorId,
                    'coordinate' => implode(',', $item['coordinate']),
                    'speed' => $item['speed'],
                    'status' => $item['status'],
                    'directions' => implode(',', $item['directions']),
                    'imei' => $item['imei'],
                    'date_time' => $item['date_time'],
                ];
            }

            // Insert batch to avoid memory issues and improve performance
            GpsData::insert($gpsDataRecords);
        }
    }
}
