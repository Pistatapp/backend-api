<?php

namespace App\Console\Commands;

use App\Models\GpsDevice;
use App\Support\GpsDeviceCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class WarmGpsDeviceCache extends Command
{
    protected $signature = 'gps:warm-device-cache';

    protected $description = 'Preload IMEI to tractor mappings into cache for GPS ingest';

    public function handle(): int
    {
        $count = 0;

        GpsDevice::query()
            ->whereNotNull('imei')
            ->with('tractor')
            ->chunkById(500, function ($devices) use (&$count) {
                foreach ($devices as $device) {
                    if (! $device->tractor) {
                        continue;
                    }

                    Cache::put(
                        "tractor_by_device_imei_{$device->imei}",
                        $device->tractor,
                        3600
                    );

                    GpsDeviceCache::put($device->imei, $device->tractor->id, $device->id);
                    $count++;
                }
            });

        $this->info("Warmed cache for {$count} GPS devices.");

        return self::SUCCESS;
    }
}
