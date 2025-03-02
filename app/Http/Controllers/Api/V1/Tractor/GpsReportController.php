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
use Illuminate\Support\Facades\Log;

class GpsReportController extends Controller
{
    public function __construct(
        private ParseDataService $parseDataService
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
        $logData = $request->getContent();
        $filePath = storage_path('logs/gps_data_' . date('Y-m-d') . '.txt');
        file_put_contents($filePath, $logData . PHP_EOL, FILE_APPEND);

        try {
            $data = $this->parseDataService->parse($request->getContent());

            Log::info('Parsed Data', $data);

            $device = $this->getDevice($data[0]['imei']);

            $lastReportStatus = end($data)['status'];

            $reportService = new LiveReportService($device, $data);
            $generatedReport = $reportService->generate();

            event(new ReportReceived($generatedReport, $device));

            event(new TractorStatus($device->tractor, $lastReportStatus));
        } catch (\Exception $e) {
            //
        } finally {
            return new JsonResponse([]);
        }
    }

    /**
     * Get device by imei
     *
     * @param string $imei
     * @return GPSDevice
     */
    private function getDevice(string $imei): ?GpsDevice
    {
        return Cache::remember('gps_device_' . $imei, 3600, function () use ($imei) {
            return GpsDevice::where('imei', $imei)
                ->whereHas('tractor')->with('tractor')->first();
        });
    }
}
