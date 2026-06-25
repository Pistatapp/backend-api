<?php

namespace App\Jobs;

use App\Models\GpsDevice;
use App\Models\Tractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ProcessGpsData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public int $timeout = 30;

    public array $backoff = [2, 5, 10];

    public function __construct(
        public array $data,
    ) {
        $this->onQueue('gps-processing');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->data[0]['imei']))->dontRelease()->expireAfter(90)];
    }

    public function handle(): void
    {
        $deviceImei = $this->data[0]['imei'];
        $tractor = $this->resolveTractor($deviceImei);

        if (! $tractor) {
            return;
        }

        StoreGpsData::dispatch($this->data, $tractor->id, $deviceImei);
    }

    private function resolveTractor(string $imei): ?Tractor
    {
        return Cache::remember("tractor_by_device_imei_{$imei}", 3600, function () use ($imei) {
            $device = GpsDevice::where('imei', $imei)->with('tractor')->first();

            if ($device && $device->tractor) {
                $device->tractor->setRelation('gpsDevice', $device);

                return $device->tractor;
            }

            return null;
        });
    }
}
