<?php

namespace App\Http\Controllers\Api\V1\User\Trucktor;

use App\Events\ReportReceived;
use App\Events\TrucktorStatus;
use App\Http\Controllers\Controller;
use App\Models\GpsData;
use App\Services\FormatDataService;
use App\Services\LiveReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\GpsDevice;

class GpsReportController extends Controller
{
    public function __construct(
        private FormatDataService $formatDataService
    ) {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function store(Request $request)
    {
        // try {

            $data = $this->prepareData($request->getContent());

            $device = $this->getDevice($data[0]['imei']);

            $lastReportStatus = $data[count($data) - 1]['status'];

            $reportService = new LiveReportService($device, $data);

            $generatedReport = $reportService->generate();

            event(new ReportReceived($generatedReport, $device));

            event(new TrucktorStatus($device->trucktor, $lastReportStatus));
        // } catch (\Exception $e) {
        //     //
        // } finally {
            return new JsonResponse([], 200);
        // }
    }

    /**
     * Prepare the data received from the GPS device
     *
     * @param string $content
     * @return array
     */
    private function prepareData(string $content)
    {
        // GpsData::create(['data' => $content]);
        $data = rtrim($content, ".");
        $data = json_decode($data, true);
        GpsData::create(['data' => is_string($data) ? $data : $data[0]['data']]);
        return $this->formatDataService->format($data);
    }

    /**
     * Get device by imei
     *
     * @param string $imei
     * @return GPSDevice
     */
    private function getDevice(string $imei)
    {
        return Cache::remember('gps_device_' . $imei, 3600, function () use ($imei) {
            return GpsDevice::where('imei', $imei)
                ->whereHas('trucktor')->with('trucktor')->first();
        });
    }
}
