<?php

namespace App\Jobs;

use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Services\NocMonitor;
use App\Support\GpsDeviceCache;
use Carbon\Carbon;
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

    /**
     * Device clocks older than this vs server time are treated as stuck/corrupt RTC
     * and resynced — otherwise live WS works while path-for-today stays empty.
     */
    private const MAX_PAST_SKEW_SECONDS = 36 * 3600;

    /** Align with IoT Gateway PiStatMaxFutureSkew. */
    private const MAX_FUTURE_SKEW_SECONDS = 24 * 3600;

    private const MIN_VALID_DATETIME = '2025-01-01 00:00:00';

    private static ?bool $hasImeiDateTimeUnique = null;

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
                [
                    'phase' => 'persisted',
                    'records' => count($records),
                    'tractor_id' => $tractor->id,
                    'database' => config('database.connections.mysql_gps.database'),
                    'first_date_time' => $records[0]['date_time'] ?? null,
                    'last_date_time' => $records[array_key_last($records)]['date_time'] ?? null,
                ]
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
        $database = (string) config('database.connections.mysql_gps.database');

        foreach (array_chunk($records, 1000) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            try {
                $gps->transaction(function () use ($gps, $chunk) {
                    $this->insertChunk($gps, $chunk);
                });
            } catch (Throwable $e) {
                $message = $e->getMessage();
                Log::warning('IngestGpsData: bulk chunk insert failed, retrying row-by-row', [
                    'database' => $database,
                    'chunk_size' => count($chunk),
                    'error' => $message,
                ]);

                if (str_contains(strtolower($message), 'partition')) {
                    Log::critical('IngestGpsData: partition error — run php artisan gps:ensure-partitions', [
                        'database' => $database,
                        'error' => $message,
                        'sample_date_time' => $chunk[0]['date_time'] ?? null,
                    ]);
                }

                $this->insertChunkRowByRow($gps, $chunk);
            }

            // Prove durability: live WS can succeed while mysql_gps stays empty.
            $probe = $chunk[array_key_last($chunk)];
            $exists = $gps->table('gps_data')
                ->where('imei', $probe['imei'] ?? null)
                ->where('date_time', $probe['date_time'] ?? null)
                ->where('tractor_id', $probe['tractor_id'] ?? null)
                ->exists();

            if (! $exists) {
                Log::error('IngestGpsData: write left no durable row — forcing plain insert', [
                    'database' => $database,
                    'imei' => $probe['imei'] ?? null,
                    'date_time' => $probe['date_time'] ?? null,
                    'tractor_id' => $probe['tractor_id'] ?? null,
                    'chunk_size' => count($chunk),
                ]);

                $this->forceInsertChunk($gps, $chunk);
            }
        }
    }

    /**
     * Prefer insertOrIgnore only when the unique key exists; otherwise plain insert
     * (IGNORE without a unique key is pointless and has masked write failures).
     *
     * @param  \Illuminate\Database\Connection  $gps
     * @param  array<int, array<string, mixed>>  $chunk
     */
    private function insertChunk($gps, array $chunk): void
    {
        if ($this->hasImeiDateTimeUniqueIndex()) {
            $gps->table('gps_data')->insertOrIgnore($chunk);

            return;
        }

        $gps->table('gps_data')->insert($chunk);
    }

    private function hasImeiDateTimeUniqueIndex(): bool
    {
        if (self::$hasImeiDateTimeUnique !== null) {
            return self::$hasImeiDateTimeUnique;
        }

        try {
            $database = config('database.connections.mysql_gps.database');
            $row = DB::connection('mysql_gps')->selectOne('
                SELECT COUNT(*) AS count
                FROM information_schema.statistics
                WHERE table_schema = ?
                  AND table_name = ?
                  AND index_name = ?
            ', [$database, 'gps_data', 'gps_data_imei_date_time_unique']);

            self::$hasImeiDateTimeUnique = ((int) ($row->count ?? 0)) > 0;
        } catch (Throwable) {
            self::$hasImeiDateTimeUnique = false;
        }

        return self::$hasImeiDateTimeUnique;
    }

    /**
     * @param  \Illuminate\Database\Connection  $gps
     * @param  array<int, array<string, mixed>>  $chunk
     */
    private function insertChunkRowByRow($gps, array $chunk): void
    {
        $inserted = 0;
        $lastError = null;

        foreach ($chunk as $row) {
            try {
                $this->insertChunk($gps, [$row]);
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

    /**
     * Last-resort write when IGNORE silently no-ops (misconfigured unique / partition).
     *
     * @param  \Illuminate\Database\Connection  $gps
     * @param  array<int, array<string, mixed>>  $chunk
     */
    private function forceInsertChunk($gps, array $chunk): void
    {
        foreach ($chunk as $row) {
            try {
                $gps->table('gps_data')->insert($row);
            } catch (Throwable $e) {
                // Duplicate after unique exists: keep first, continue trail.
                if ($this->isDuplicateKeyException($e)) {
                    continue;
                }

                Log::error('IngestGpsData: force insert failed', [
                    'imei' => $row['imei'] ?? null,
                    'date_time' => $row['date_time'] ?? null,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }

    private function isDuplicateKeyException(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate')
            || str_contains($message, '1062');
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

        // Unique (imei, date_time) + second-precision clocks: colliding rows in one
        // batch (gateway retries, RTC resync to server "now") would otherwise be
        // insertOrIgnore'd down to a single DB point while WS still shows live logs.
        return $this->ensureUniqueDateTimes($records);
    }

    /**
     * Normalize wire date_time to Y-m-d H:i:s. Resync stuck/corrupt device clocks so
     * live traffic is stored under "today" and the path API can find it.
     *
     * Evidence on prod (tractor 38): MAX(date_time)=2026-07-30 while live WS still
     * moved markers on 2026-08-01 — path-for-today was empty because rows kept the
     * stuck Jul-30 clock.
     */
    private function normalizeDeviceDateTime(string $raw, string $imei, int $index): string
    {
        $now = Carbon::now();
        $fallback = $now->format('Y-m-d H:i:s');

        if (trim($raw) === '') {
            Log::warning('IngestGpsData: empty date_time — using server time', [
                'imei' => $imei,
                'index' => $index,
            ]);

            return $fallback;
        }

        try {
            $dt = Carbon::parse($raw);
        } catch (Throwable) {
            Log::warning('IngestGpsData: unparseable date_time — using server time', [
                'imei' => $imei,
                'index' => $index,
                'raw' => $raw,
            ]);

            return $fallback;
        }

        $formatted = $dt->format('Y-m-d H:i:s');

        if ($formatted < self::MIN_VALID_DATETIME) {
            Log::warning('IngestGpsData: pre-2025 date_time — resync to server', [
                'imei' => $imei,
                'index' => $index,
                'raw' => $formatted,
            ]);

            return $fallback;
        }

        if ($dt->lt($now->copy()->subSeconds(self::MAX_PAST_SKEW_SECONDS))) {
            Log::warning('IngestGpsData: date_time too far in the past — resync to server', [
                'imei' => $imei,
                'index' => $index,
                'raw' => $formatted,
                'server_now' => $fallback,
                'skew_hours' => round($dt->diffInSeconds($now) / 3600, 1),
            ]);

            return $fallback;
        }

        if ($dt->gt($now->copy()->addSeconds(self::MAX_FUTURE_SKEW_SECONDS))) {
            Log::warning('IngestGpsData: date_time too far in the future — resync to server', [
                'imei' => $imei,
                'index' => $index,
                'raw' => $formatted,
                'server_now' => $fallback,
            ]);

            return $fallback;
        }

        return $formatted;
    }

    /**
     * Normalize date_time and resolve (imei, date_time) collisions inside one packet.
     *
     * Same-second points are ordered by geographic progression (nearest-neighbor
     * chain along the trail), then given consecutive seconds so
     * gps_data_imei_date_time_unique + insertOrIgnore does not drop them.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private function ensureUniqueDateTimes(array $records): array
    {
        if ($records === []) {
            return [];
        }

        $prepared = [];
        foreach ($records as $index => $record) {
            $dt = $this->normalizeDeviceDateTime(
                (string) ($record['date_time'] ?? ''),
                (string) ($record['imei'] ?? ''),
                $index
            );

            [$lat, $lon] = $this->parseCoordinatePair($record['coordinate'] ?? null);
            $prepared[] = [
                'record' => $record,
                'imei' => (string) ($record['imei'] ?? ''),
                'date_time' => $dt,
                'lat' => $lat,
                'lon' => $lon,
                'index' => $index,
            ];
        }

        $byImei = [];
        foreach ($prepared as $item) {
            $byImei[$item['imei']][] = $item;
        }

        $resolved = [];
        foreach ($byImei as $imeiItems) {
            $resolved = array_merge($resolved, $this->assignUniqueDateTimesForImei($imeiItems));
        }

        usort($resolved, fn (array $a, array $b) => $a['index'] <=> $b['index']);

        return array_map(static function (array $item) {
            $record = $item['record'];
            $record['date_time'] = $item['date_time'];

            return $record;
        }, $resolved);
    }

    /**
     * @param  array<int, array{record: array<string, mixed>, imei: string, date_time: string, lat: float, lon: float, index: int}>  $items
     * @return array<int, array{record: array<string, mixed>, imei: string, date_time: string, lat: float, lon: float, index: int}>
     */
    private function assignUniqueDateTimesForImei(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $groups[$item['date_time']][] = $item;
        }
        ksort($groups);

        $used = [];
        $out = [];
        $previous = null;

        foreach ($groups as $baseDateTime => $group) {
            $ordered = count($group) > 1
                ? $this->orderBySpatialProgression($group, $previous)
                : $group;

            $dt = $baseDateTime;
            foreach ($ordered as $item) {
                while (isset($used[$dt])) {
                    $dt = Carbon::parse($dt)->addSecond()->format('Y-m-d H:i:s');
                }

                $item['date_time'] = $dt;
                $used[$dt] = true;
                $previous = $item;
                $out[] = $item;
                $dt = Carbon::parse($dt)->addSecond()->format('Y-m-d H:i:s');
            }
        }

        return $out;
    }

    /**
     * Nearest-neighbor chain: start nearest to previous point (or earliest packet
     * index), then repeatedly append the spatially closest remaining point.
     *
     * @param  array<int, array{record: array<string, mixed>, imei: string, date_time: string, lat: float, lon: float, index: int}>  $group
     * @param  array{lat: float, lon: float}|null  $previous
     * @return array<int, array{record: array<string, mixed>, imei: string, date_time: string, lat: float, lon: float, index: int}>
     */
    private function orderBySpatialProgression(array $group, ?array $previous): array
    {
        $remaining = array_values($group);
        $ordered = [];

        if ($previous !== null) {
            $startPos = 0;
            $best = null;
            foreach ($remaining as $i => $item) {
                $d = $this->haversineMeters($previous['lat'], $previous['lon'], $item['lat'], $item['lon']);
                if ($best === null || $d < $best) {
                    $best = $d;
                    $startPos = $i;
                }
            }
            $current = $remaining[$startPos];
            array_splice($remaining, $startPos, 1);
        } else {
            usort($remaining, fn (array $a, array $b) => $a['index'] <=> $b['index']);
            $current = array_shift($remaining);
        }

        $ordered[] = $current;

        while ($remaining !== []) {
            $nearestPos = 0;
            $best = null;
            foreach ($remaining as $i => $item) {
                $d = $this->haversineMeters($current['lat'], $current['lon'], $item['lat'], $item['lon']);
                if ($best === null || $d < $best || ($d === $best && $item['index'] < $remaining[$nearestPos]['index'])) {
                    $best = $d;
                    $nearestPos = $i;
                }
            }
            $current = $remaining[$nearestPos];
            array_splice($remaining, $nearestPos, 1);
            $ordered[] = $current;
        }

        return $ordered;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function parseCoordinatePair(mixed $coordinate): array
    {
        if (is_string($coordinate)) {
            $decoded = json_decode($coordinate, true);
            if (is_array($decoded)) {
                return [(float) ($decoded[0] ?? 0), (float) ($decoded[1] ?? 0)];
            }

            $parts = explode(',', $coordinate, 2);
            if (count($parts) === 2) {
                return [(float) $parts[0], (float) $parts[1]];
            }
        }

        if (is_array($coordinate)) {
            return [(float) ($coordinate[0] ?? 0), (float) ($coordinate[1] ?? 0)];
        }

        return [0.0, 0.0];
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $earthRadius * asin(min(1.0, sqrt($a)));
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
