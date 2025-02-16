<?php

namespace App\Http\Controllers\Api\V1\Trucktor;

use App\Events\ReportReceived;
use App\Events\TrucktorStatus;
use App\Http\Controllers\Controller;
use App\Services\ParseDataService;
use App\Services\LiveReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\GpsDevice;

class GpsReportController extends Controller
{
    public function __construct(
        private ParseDataService $parseDataService,
        private LiveReportService $liveReportService
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

            $lastReportStatus = end($data)['status'];

            $reportService = new LiveReportService($device, $data);
            $generatedReport = $reportService->generate();

            event(new ReportReceived($generatedReport, $device));
            event(new TrucktorStatus($device->trucktor, $lastReportStatus));
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
            return GpsDevice::with('trucktor')
                ->where('imei', $imei)
                ->whereHas('trucktor')
                ->first();
        });
    }
}
