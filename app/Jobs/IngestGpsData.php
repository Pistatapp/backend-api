<?php

namespace App\Jobs;

use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Services\NocMonitor;
use App\Support\GpsDeviceCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Laravel GPS ingest job (successor to ProcessGpsData + StoreGpsData).
 * Internal only — does not change public Android REST/WS contracts.
 */
class IngestGpsData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public int $timeout = 60;

    public array $backoff = [2, 5, 10];

    public function __construct(
        public array $data,
        public ?string $traceId = null,
    ) {
        $this->onQueue('gps-processing');
    }

    /**
     * Serialize work per IMEI. Overlapping jobs are released (3s) instead of
     * being silently discarded.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $imei = $this->data[0]['imei'] ?? 'empty-batch';

        return [
            (new WithoutOverlapping((string) $imei))
                ->releaseAfter(3)
                ->expireAfter(90),
        ];
    }

    public function handle(): void
    {
        if ($this->data === [] || ! isset($this->data[0]['imei'])) {
            Log::warning('IngestGpsData: empty or missing IMEI in GPS batch', [
                'batch_size' => count($this->data),
            ]);

            return;
        }

        $deviceImei = (string) $this->data[0]['imei'];
        $tractor = $this->resolveTractor($deviceImei);

        if (! $tractor) {
            Log::warning('IngestGpsData: unbound IMEI has no tractor assignment; GPS batch discarded', [
                'imei' => $deviceImei,
                'batch_size' => count($this->data),
            ]);
            NocMonitor::emit(
                'PISTAT_DELIVERY',
                'drop',
                $deviceImei,
                $this->traceId,
                'Unbound IMEI — no tractor assignment',
                ['phase' => 'persist', 'batch_size' => count($this->data)],
                'unbound IMEI'
            );

            return;
        }

        $records = $this->prepareBatch($this->data, $tractor->id, $deviceImei);

        if ($records !== []) {
            $this->insertWithRecovery($records);
            NocMonitor::emit(
                'PISTAT_DELIVERY',
                'success',
                $deviceImei,
                $this->traceId,
                'PiStat mysql_gps persisted',
                ['phase' => 'persisted', 'records' => count($records), 'tractor_id' => $tractor->id]
            );
        } else {
            NocMonitor::emit(
                'PISTAT_DELIVERY',
                'drop',
                $deviceImei,
                $this->traceId,
                'PiStat prepareBatch produced zero rows',
                ['phase' => 'persist'],
                'empty prepared batch'
            );
        }

        BroadcastGpsEvents::dispatch($this->data, $tractor->id, $deviceImei, $this->traceId);
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
     * Bulk insertOrIgnore on mysql_gps; on chunk failure, retry row-by-row so
     * one bad row cannot discard a healthy batch.
     *
     * @param  array<int, array<string, mixed>>  $records
     */
    private function insertWithRecovery(array $records): void
    {
        $gps = DB::connection('mysql_gps');

        foreach (array_chunk($records, 1000) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            try {
                $gps->transaction(function () use ($gps, $chunk) {
                    $gps->table('gps_data')->insertOrIgnore($chunk);
                });

                continue;
            } catch (Throwable $e) {
                Log::warning('IngestGpsData: bulk chunk insert failed, retrying row-by-row', [
                    'chunk_size' => count($chunk),
                    'error' => $e->getMessage(),
                ]);
            }

            $inserted = 0;
            $lastError = null;

            foreach ($chunk as $row) {
                try {
                    $gps->table('gps_data')->insertOrIgnore($row);
                    $inserted++;
                } catch (Throwable $rowError) {
                    $lastError = $rowError;
                    Log::error('IngestGpsData: dropping unrecoverable gps_data row', [
                        'imei' => $row['imei'] ?? null,
                        'date_time' => $row['date_time'] ?? null,
                        'error' => $rowError->getMessage(),
                    ]);
                }
            }

            if ($inserted === 0 && $lastError !== null) {
                throw $lastError;
            }
        }
    }

    /**
     * Whitelist columns and coerce defaults. Skip/log frames that cannot be stored.
     *
     * @return array<int, array<string, mixed>>
     */
    private function prepareBatch(array $data, int $tractorId, string $fallbackImei): array
    {
        $records = [];

        foreach ($data as $index => $item) {
            if (! is_array($item)) {
                Log::warning('IngestGpsData: skipping non-array GPS frame', [
                    'index' => $index,
                    'tractor_id' => $tractorId,
                ]);

                continue;
            }

            $record = $this->sanitizeRow($item, $tractorId, $fallbackImei, $index);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function sanitizeRow(array $item, int $tractorId, string $fallbackImei, int $index): ?array
    {
        $coordinate = $this->encodeJsonField($item['coordinate'] ?? null, 'coordinate', $index, $tractorId);
        $directions = $this->encodeJsonField($item['directions'] ?? [], 'directions', $index, $tractorId);

        if ($coordinate === null || $directions === null) {
            return null;
        }

        $imei = isset($item['imei']) && is_scalar($item['imei']) && (string) $item['imei'] !== ''
            ? (string) $item['imei']
            : $fallbackImei;

        $dateTime = $item['date_time'] ?? null;
        if (! is_scalar($dateTime) || (string) $dateTime === '') {
            Log::warning('IngestGpsData: skipping GPS frame with missing date_time', [
                'index' => $index,
                'imei' => $imei,
                'tractor_id' => $tractorId,
            ]);

            return null;
        }

        return [
            'tractor_id' => $tractorId,
            'coordinate' => $coordinate,
            'speed' => (int) ($item['speed'] ?? 0),
            'status' => (int) ($item['status'] ?? 0),
            'directions' => $directions,
            'imei' => $imei,
            'date_time' => (string) $dateTime,
        ];
    }

    private function encodeJsonField(mixed $value, string $field, int $index, int $tractorId): ?string
    {
        if ($value === null) {
            Log::warning('IngestGpsData: skipping GPS frame with missing field', [
                'index' => $index,
                'field' => $field,
                'tractor_id' => $tractorId,
            ]);

            return null;
        }

        if (is_string($value)) {
            json_decode($value);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('IngestGpsData: skipping GPS frame with invalid JSON string', [
                    'index' => $index,
                    'field' => $field,
                    'error' => json_last_error_msg(),
                    'tractor_id' => $tractorId,
                ]);

                return null;
            }

            return $value;
        }

        $encoded = json_encode($value);
        if ($encoded === false) {
            Log::warning('IngestGpsData: skipping GPS frame that failed json_encode', [
                'index' => $index,
                'field' => $field,
                'tractor_id' => $tractorId,
            ]);

            return null;
        }

        return $encoded;
    }
}
