<?php

namespace App\Jobs;

use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Support\GpsDeviceCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IngestGpsData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public int $timeout = 60;

    public array $backoff = [2, 5, 10];

    public function __construct(
        public array $data,
    ) {
        $this->onQueue('gps-processing');
    }

    public function handle(): void
    {
        $deviceImei = $this->data[0]['imei'];
        $tractor = $this->resolveTractor($deviceImei);

        if (! $tractor) {
            return;
        }

        $records = $this->prepareBatch($this->data, $tractor->id);

        DB::connection('mysql_gps')->transaction(function () use ($records) {
            foreach (array_chunk($records, 1000) as $chunk) {
                DB::connection('mysql_gps')->table('gps_data')->insertOrIgnore($chunk);
            }
        });

        BroadcastGpsEvents::dispatch($this->data, $tractor->id, $deviceImei);
    }

    private function resolveTractor(string $imei): ?Tractor
    {
        return Cache::remember("tractor_by_device_imei_{$imei}", 3600, function () use ($imei) {
            $device = GpsDevice::where('imei', $imei)->with('tractor')->first();

            if ($device && $device->tractor) {
                $device->tractor->setRelation('gpsDevice', $device);

                GpsDeviceCache::put($imei, $device->tractor->id, $device->id);

                return $device->tractor;
            }

            return null;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function prepareBatch(array $data, int $tractorId): array
    {
        return array_map(function (array $item) use ($tractorId) {
            return [
                'tractor_id' => $tractorId,
                'coordinate' => json_encode($item['coordinate']),
                'speed' => $item['speed'],
                'status' => $item['status'],
                'directions' => json_encode($item['directions']),
                'imei' => $item['imei'],
                'date_time' => $item['date_time'],
            ];
        }, $data);
    }
}
