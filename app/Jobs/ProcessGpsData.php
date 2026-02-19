<?php

namespace App\Jobs;

use App\Models\Tractor;
use App\Services\ParseDataService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessGpsData implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public int $timeout = 30;

    public array $backoff = [2, 5, 10];

    public function __construct(
        public string $rawData,
    ) {
        $this->onQueue('gps-processing');
    }

    public function handle(ParseDataService $parseDataService): void
    {
        $data = $parseDataService->parse($this->rawData);

        if (empty($data)) {
            return;
        }

        $deviceImei = $data[0]['imei'];
        $tractor = $this->resolveTractor($deviceImei);

        if ($tractor === null) {
            Log::warning('ProcessGpsData: no tractor found for IMEI', ['imei' => $deviceImei]);
            return;
        }

        Bus::chain([
            new StoreGpsData($data, $tractor->id),
            new BroadcastGpsEvents($data, $tractor->id, $deviceImei),
        ])->onQueue('gps-processing')->dispatch();
    }

    private function resolveTractor(string $imei): ?Tractor
    {
        return Cache::remember("tractor_by_device_imei_{$imei}", 3600, function () use ($imei) {
            return Tractor::whereHas('gpsDevice', function ($query) use ($imei) {
                $query->where('imei', $imei);
            })->with('gpsDevice')->first();
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessGpsData failed', [
            'raw_data_length' => strlen($this->rawData),
            'error' => $exception->getMessage(),
        ]);
    }
}
