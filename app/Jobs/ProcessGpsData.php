<?php

namespace App\Jobs;

use App\Services\ParseDataService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\GpsData;
use App\Models\Tractor;
use Illuminate\Support\Facades\Cache;
use App\Events\TractorStatus;
use App\Events\ReportReceived;

class ProcessGpsData implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $rawData,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(ParseDataService $parseDataService): void
    {
        $data = $parseDataService->parse($this->rawData);

        $deviceImei = $data[0]['imei'];

        $tractor = $this->fetchTractorByDeviceImei($deviceImei);

        // Save parsed GPS data to database
        $this->saveGpsData($data, $tractor->id);

        // Update tractor status
        $lastStatus = end($data)['status'];
        event(new TractorStatus($tractor, $lastStatus));

        // Get device for ReportReceived event (only fire if device exists)
        $device = $tractor->gpsDevice;
        event(new ReportReceived($data, $device));
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
                    'coordinate' => json_encode($item['coordinate']),
                    'speed' => $item['speed'],
                    'status' => $item['status'],
                    'directions' => json_encode($item['directions']),
                    'imei' => $item['imei'],
                    'date_time' => $item['date_time'],
                ];
            }

            // Insert batch to avoid memory issues and improve performance
            GpsData::insert($gpsDataRecords);
        }
    }
}
