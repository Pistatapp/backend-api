<?php

namespace App\Jobs;

use App\Events\ReportReceived;
use App\Events\TractorStatus;
use App\Models\GpsDevice;
use App\Models\Tractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProcessGpsData implements ShouldQueue
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

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        if (empty($this->data) || !isset($this->data[0]['imei'])) {
            return [];
        }

        // Prevent race conditions by serializing jobs for the same device
        // This helps reduce database deadlocks and processing conflicts
        return [(new WithoutOverlapping($this->data[0]['imei']))->dontRelease()->expireAfter(30)];
    }

    public function handle(): void
    {
        if (empty($this->data)) {
            return;
        }

        $deviceImei = $this->data[0]['imei'];
        $tractor = $this->resolveTractor($deviceImei);

        if (!$tractor) {
            return;
        }

        $lastStatus = end($this->data)['status'];

        $records = $this->prepareBatch($this->data, $tractor);

        // Use transaction for data integrity
        DB::connection('mysql_gps')->transaction(function () use ($records) {
            // Chunk inserts to handle large batches efficiently
            foreach (array_chunk($records, 1000) as $chunk) {
                DB::table('gps_data')->insert($chunk);
            }
        });

        // Events are fired after successful insertion
        $device = $tractor->gpsDevice;
        event(new TractorStatus($tractor, $lastStatus));
        event(new ReportReceived($this->data, $device));
    }

    /**
     * Prepare the batch for insertion.
     *
     * @param array $data The batch of data to prepare.
     * @param Tractor $tractor The tractor to prepare the batch for.
     * @return array The prepared batch.
     */
    private function prepareBatch(array $data, Tractor $tractor): array
    {
        $tractorId = $tractor->id;

        return array_map(function (array $item) use ($tractorId) {
            return array_merge($item, [
                'tractor_id' => $tractorId,
                'coordinate' => json_encode($item['coordinate']),
                'speed' => $item['speed'],
                'status' => $item['status'],
                'directions' => json_encode($item['directions']),
                'imei' => $item['imei'],
                'date_time' => $item['date_time'],
            ]);
        }, $data);
    }

    /**
     * Resolve the tractor by device IMEI.
     *
     * @param string $imei The device IMEI.
     * @return Tractor|null The tractor.
     */
    private function resolveTractor(string $imei): ?Tractor
    {
        return Cache::remember("tractor_by_device_imei_{$imei}", 3600, function () use ($imei) {
            // Optimized query: Find device first, then tractor
            // This avoids a potentially slow subquery on the tractors table
            $device = GpsDevice::where('imei', $imei)->with('tractor')->first();

            if ($device && $device->tractor) {
                // Set the relation manually to avoid an extra query when accessing $tractor->gpsDevice
                $device->tractor->setRelation('gpsDevice', $device);
                return $device->tractor;
            }

            return null;
        });
    }
}
