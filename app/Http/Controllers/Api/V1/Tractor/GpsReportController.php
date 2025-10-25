<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Services\ParseDataService;
use App\Models\GpsDevice;
use App\Models\GpsData;
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

            $device = $this->fetchDeviceByImei($deviceImei);

            // Save parsed GPS data to database
            $this->saveGpsData($data, $device->id);

            // Update tractor status
            $tractor = $device->tractor;
            $lastStatus = end($data)['status'];
            $tractor->update(['is_working' => $lastStatus]);
            event(new TractorStatus($tractor, $lastStatus));
            event(new ReportReceived($data, $device));
        } catch (\Exception $e) {
            //
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
     * @param int $deviceId GPS device ID
     * @return void
     */
    private function saveGpsData(array $data, int $deviceId): void
    {
        $gpsDataRecords = [];

        foreach ($data as $item) {
            $gpsDataRecords[] = [
                'gps_device_id' => $deviceId,
                'coordinate' => json_encode($item['coordinate']),
                'speed' => $item['speed'],
                'status' => $item['status'],
                'directions' => json_encode($item['directions']),
                'imei' => $item['imei'],
                'date_time' => $item['date_time'],
            ];
        }

        // Use bulk insert for better performance
        GpsData::insert($gpsDataRecords);
    }
}
