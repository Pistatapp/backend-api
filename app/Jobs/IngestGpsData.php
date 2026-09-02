<?php

namespace App\Jobs;

use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Services\GpsIngressLedger;
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

    public function __construct(
        public array $data,
        public ?string $traceId = null,
        public array $eventMetadata = [],
        public ?string $gatewayReceivedAt = null,
    ) {
        $this->onConnection(config('queue.default', 'redis'));
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
                ->expireAfter(60),
        ];
    }

    public function handle(): void
    {
        if ($this->data === []) {
            Log::warning('IngestGpsData: empty or missing IMEI in GPS batch', [
                'batch_size' => count($this->data),
            ]);

            return;
        }

        $groups = [];
        foreach ($this->data as $index => $item) {
            $imei = is_array($item) && isset($item['imei']) && is_scalar($item['imei'])
                ? trim((string) $item['imei'])
                : '';

            if ($imei === '') {
                $this->quarantineItem($item, (int) $index, 'Missing IMEI');
                continue;
            }

            $groups[$imei][] = ['item' => $item, 'index' => (int) $index];
        }

        foreach ($groups as $deviceImei => $group) {
            $tractor = $this->resolveTractor($deviceImei);

            if (! $tractor) {
                $reason = 'Unbound IMEI — no tractor assignment';
                Log::warning('IngestGpsData: IMEI quarantined because it has no tractor assignment', [
                    'imei' => $deviceImei,
                    'batch_size' => count($group),
                ]);
                foreach ($group as $frame) {
                    $this->quarantineItem($frame['item'], $frame['index'], $reason);
                }
                NocMonitor::emit(
                    'PISTAT_DELIVERY',
                    'quarantine',
                    $deviceImei,
                    $this->traceId,
                    $reason,
                    ['phase' => 'persist', 'batch_size' => count($group)],
                    $reason
                );

                continue;
            }

            $records = $this->prepareBatch(
                array_column($group, 'item'),
                $tractor->id,
                $deviceImei,
                array_column($group, 'index')
            );

            if ($records === []) {
                Log::warning('IngestGpsData: no valid rows remained after item validation', [
                    'imei' => $deviceImei,
                    'batch_size' => count($group),
                    'tractor_id' => $tractor->id,
                ]);
                continue;
            }

            // Any persistence exception escapes the job. Laravel keeps the job
            // pending/retryable instead of ACKing a partially written batch.
            $persisted = $this->insertWithRecovery($records);
            if ($persisted !== count($records)) {
                throw new \RuntimeException(
                    'IngestGpsData persisted '.$persisted.'/'.count($records).' rows for IMEI '.$deviceImei
                );
            }

            foreach ($group as $frame) {
                $this->markEventForIndex($frame['index'], GpsIngressLedger::PERSISTED);
            }

            NocMonitor::emit(
                'PISTAT_DELIVERY',
                'success',
                $deviceImei,
                $this->traceId,
                'PiStat mysql_gps persisted',
                [
                    'phase' => 'persisted',
                    'records' => $persisted,
                    'prepared' => count($records),
                    'tractor_id' => $tractor->id,
                    'database' => config('database.connections.mysql_gps.database'),
                    'first_date_time' => $records[0]['date_time'] ?? null,
                    'last_date_time' => $records[array_key_last($records)]['date_time'] ?? null,
                ]
            );

            // Only broadcast after durable write — otherwise markers move while path stays empty.
            BroadcastGpsEvents::dispatch(
                array_column($group, 'item'),
                $tractor->id,
                $deviceImei,
                $this->traceId
            );
        }
    }

    public function failed(Throwable $e): void
    {
        foreach ($this->eventMetadata as $event) {
            app(GpsIngressLedger::class)->mark(
                (string) ($event['event_id'] ?? ''),
                GpsIngressLedger::DLQ_REPLAYABLE,
                $e->getMessage()
            );
        }
    }

    private function quarantineItem(mixed $item, int $index, string $reason): void
    {
        $event = $this->eventMetadataForIndex($index, $item);
        app(GpsIngressLedger::class)->quarantine($event, $reason);
        Log::warning('IngestGpsData: GPS item quarantined', [
            'event_id' => $event['event_id'],
            'imei' => $event['imei'],
            'batch_index' => $index,
            'reason' => $reason,
        ]);
    }

    private function markEventForIndex(int $index, string $status): void
    {
        foreach ($this->eventMetadata as $event) {
            if ((int) ($event['_job_index'] ?? $event['batch_index'] ?? -1) !== $index) {
                continue;
            }

            app(GpsIngressLedger::class)->mark((string) $event['event_id'], $status);

            return;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function eventMetadataForIndex(int $index, mixed $item): array
    {
        foreach ($this->eventMetadata as $event) {
            if ((int) ($event['_job_index'] ?? $event['batch_index'] ?? -1) === $index) {
                return $event;
            }
        }

        $raw = is_string($item)
            ? $item
            : (string) json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $imei = is_array($item) && isset($item['imei']) && is_scalar($item['imei'])
            ? (string) $item['imei']
            : null;
        $dateTime = is_array($item) && isset($item['date_time']) && is_scalar($item['date_time'])
            ? (string) $item['date_time']
            : null;
        $payloadHash = hash('sha256', $raw);

        return [
            'event_id' => hash('sha256', ($imei ?? '').'|'.($dateTime ?? '').'|'.$payloadHash.'|'.$index),
            'trace_id' => $this->traceId,
            'imei' => $imei,
            'device_recorded_at' => $dateTime,
            'gateway_received_at' => $this->gatewayReceivedAt ?? now()->format('Y-m-d H:i:s'),
            'payload_hash' => $payloadHash,
            'raw_payload' => $raw,
            'batch_index' => $index,
            'attempts' => 0,
        ];
    }

    private function resolveTractor(string $imei): ?Tractor
    {
        $cacheKey = "tractor_by_device_imei_{$imei}";
        $cachedTractorId = Cache::get($cacheKey);
        if (is_numeric($cachedTractorId) && (int) $cachedTractorId > 0) {
            $tractor = Tractor::find((int) $cachedTractorId);
            if ($tractor) {
                return $tractor;
            }
        }

        // Do not cache null: a device can be bound after an earlier quarantine.
        $device = GpsDevice::where('imei', $imei)->with('tractor')->first();
        if (! $device || ! $device->tractor) {
            return null;
        }

        $device->tractor->setRelation('gpsDevice', $device);
        Cache::put($cacheKey, $device->tractor->id, 3600);
        GpsDeviceCache::put($imei, $device->tractor->id, $device->id);

        return $device->tractor;
    }

    /**
     * Persist rows to mysql_gps with reconnect / row-level recovery.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return int Number of rows successfully written (or already present as duplicate)
     */
    private function insertWithRecovery(array $records): int
    {
        $gps = DB::connection('mysql_gps');
        $database = (string) config('database.connections.mysql_gps.database');
        $written = 0;

        $this->ensureGpsConnection($gps);

        foreach (array_chunk($records, 200) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            try {
                $this->ensureGpsConnection($gps);
                $gps->transaction(function () use ($gps, $chunk, &$written) {
                    $written += $this->insertChunkCounting($gps, $chunk);
                });
            } catch (Throwable $e) {
                Log::warning('IngestGpsData: bulk chunk insert failed, retrying row-by-row', [
                    'database' => $database,
                    'chunk_size' => count($chunk),
                    'error' => $e->getMessage(),
                ]);

                if (str_contains(strtolower($e->getMessage()), 'partition')) {
                    Log::critical('IngestGpsData: partition error on bulk insert', [
                        'database' => $database,
                        'error' => $e->getMessage(),
                        'sample_date_time' => $chunk[0]['date_time'] ?? null,
                    ]);
                }

                $this->ensureGpsConnection($gps);
                $written += $this->insertRowsIndividually($gps, $chunk);
            }
        }

        Log::info('IngestGpsData: persist result', [
            'database' => $database,
            'prepared' => count($records),
            'written' => $written,
            'imei' => $records[0]['imei'] ?? null,
            'tractor_id' => $records[0]['tractor_id'] ?? null,
        ]);

        if ($written !== count($records)) {
            throw new \RuntimeException(
                'GPS persistence incomplete: '.$written.'/'.count($records).' rows written'
            );
        }

        return $written;
    }

    /**
     * @param  \Illuminate\Database\Connection  $gps
     */
    private function ensureGpsConnection($gps): void
    {
        try {
            $gps->select('SELECT 1');
        } catch (Throwable) {
            try {
                $gps->disconnect();
            } catch (Throwable) {
                // ignore
            }
            $gps->reconnect();
        }
    }

    private function isStaleConnectionException(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'server has gone away')
            || str_contains($message, 'lost connection')
            || str_contains($message, 'is dead')
            || str_contains($message, 'broken pipe')
            || str_contains($message, 'error while sending')
            || (str_contains($message, '2006'))
            || (str_contains($message, '2013'));
    }

    /**
     * @param  \Illuminate\Database\Connection  $gps
     * @param  array<int, array<string, mixed>>  $chunk
     */
    private function insertChunkCounting($gps, array $chunk): int
    {
        return $this->persistChunkWithDelta($gps, $chunk);
    }

    /**
     * Delta ingest: skip exact replay duplicates (imei + date_time + coordinate)
     * and insert every other event. A different payload at the same device time
     * is retained as a separate point; it must not overwrite the first frame.
     *
     * @param  \Illuminate\Database\Connection  $gps
     * @param  array<int, array<string, mixed>>  $chunk
     */
    private function persistChunkWithDelta($gps, array $chunk): int
    {
        if ($chunk === []) {
            return 0;
        }

        $originalCount = count($chunk);
        $chunk = $this->deduplicateIncomingChunk($chunk);
        [$toInsert, $skipped] = $this->resolveChunkDelta($gps, $chunk);
        // Exact duplicate frames are valid retry deliveries. They already have
        // one durable logical point, so count them as accepted without inserting
        // another physical row.
        $written = $skipped + ($originalCount - count($chunk));

        if ($toInsert === []) {
            return $written;
        }

        // Never use insertOrIgnore: it turns a valid event into an untracked
        // drop. Exact replay duplicates were handled above; any other database
        // exception must retry or become a replayable DLQ event.
        $gps->table('gps_data')->insert(array_map(
            fn (array $row): array => $this->gpsInsertRow($row),
            $toInsert
        ));
        $written += count($toInsert);

        return $written;
    }

    /**
     * Within one gateway batch keep the last frame for an exact imei+time+location key.
     *
     * @param  array<int, array<string, mixed>>  $chunk
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateIncomingChunk(array $chunk): array
    {
        $deduped = [];
        foreach ($chunk as $row) {
            $key = ($row['imei'] ?? '').'|'.($row['date_time'] ?? '').'|'.$this->normalizedCoordinateKey($row['coordinate'] ?? null);
            $deduped[$key] = $row;
        }

        return array_values($deduped);
    }

    /**
     * @param  \Illuminate\Database\Connection  $gps
     * @param  array<int, array<string, mixed>>  $chunk
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function resolveChunkDelta($gps, array $chunk): array
    {
        $imei = (string) ($chunk[0]['imei'] ?? '');
        $times = array_values(array_unique(array_column($chunk, 'date_time')));

        if ($imei === '' || $times === []) {
            return [$chunk, 0];
        }

        $existing = $gps->table('gps_data')
            ->select(['id', 'imei', 'date_time', 'coordinate'])
            ->where('imei', $imei)
            ->whereIn('date_time', $times)
            ->orderBy('id')
            ->get();

        $byDateTime = [];
        foreach ($existing as $row) {
            $byDateTime[(string) $row->date_time][] = $row;
        }

        $toInsert = [];
        $skipped = 0;

        foreach ($chunk as $row) {
            $dateTime = (string) $row['date_time'];
            $coordKey = $this->normalizedCoordinateKey($row['coordinate'] ?? null);
            $matches = $byDateTime[$dateTime] ?? [];

            foreach ($matches as $match) {
                if ($this->normalizedCoordinateKey($match->coordinate) === $coordKey) {
                    $skipped++;

                    continue 2;
                }
            }

            if ($matches !== []) {
                // Same device timestamp with a different payload is a separate
                // event (often an offline correction), not an update that may
                // overwrite the original route point.
                $toInsert[] = $row;
                continue;
            }

            $toInsert[] = $row;
        }

        return [$toInsert, $skipped];
    }

    private function normalizedCoordinateKey(mixed $coordinate): string
    {
        [$lat, $lon] = $this->parseCoordinatePair($coordinate);

        return sprintf('%.6f,%.6f', $lat, $lon);
    }

    /**
     * @param  \Illuminate\Database\Connection  $gps
     * @param  array<int, array<string, mixed>>  $chunk
     */
    private function countExistingInChunk($gps, array $chunk): int
    {
        $imei = (string) ($chunk[0]['imei'] ?? '');
        $tractorId = (int) ($chunk[0]['tractor_id'] ?? 0);
        $times = array_values(array_unique(array_column($chunk, 'date_time')));

        if ($imei === '' || $tractorId < 1 || $times === []) {
            return 0;
        }

        return (int) $gps->table('gps_data')
            ->where('imei', $imei)
            ->where('tractor_id', $tractorId)
            ->whereIn('date_time', $times)
            ->count();
    }

    /**
     * @param  \Illuminate\Database\Connection  $gps
     * @param  array<int, array<string, mixed>>  $chunk
     */
    private function insertRowsIndividually($gps, array $chunk): int
    {
        $written = 0;
        $failed = [];

        foreach ($chunk as $row) {
            $attempts = 0;
            $rowWritten = false;
            while ($attempts < 3) {
                $attempts++;
                try {
                    $deltaWritten = $this->persistChunkWithDelta($gps, [$row]);
                    if ($deltaWritten > 0) {
                        $written += $deltaWritten;
                        $rowWritten = true;
                    }
                    break;
                } catch (Throwable $e) {
                    if ($this->isDuplicateKeyException($e) && $this->rowAlreadyPersisted($gps, $row)) {
                        // A concurrent retry won the insert. Count it only after
                        // verifying the exact logical point exists.
                        $written++;
                        $rowWritten = true;
                        break;
                    }

                    if ($this->isStaleConnectionException($e) && $attempts < 3) {
                        Log::warning('IngestGpsData: stale MySQL connection — reconnecting', [
                            'attempt' => $attempts,
                            'error' => $e->getMessage(),
                        ]);
                        $this->ensureGpsConnection($gps);
                        continue;
                    }

                    if (str_contains(strtolower($e->getMessage()), 'partition') && $attempts < 3) {
                        // Retry without changing the device timestamp. A missing
                        // partition is an infrastructure failure, not bad data.
                        Log::critical('IngestGpsData: partition miss — retaining device timestamp and retrying', [
                            'imei' => $row['imei'] ?? null,
                            'date_time' => $row['date_time'] ?? null,
                            'error' => $e->getMessage(),
                        ]);
                        $this->ensureGpsConnection($gps);
                        continue;
                    }

                    $failed[] = [
                        'imei' => $row['imei'] ?? null,
                        'date_time' => $row['date_time'] ?? null,
                        'error' => $e->getMessage(),
                    ];
                    break;
                }
            }

            if (! $rowWritten && $attempts >= 3 && $failed === []) {
                $failed[] = [
                    'imei' => $row['imei'] ?? null,
                    'date_time' => $row['date_time'] ?? null,
                    'error' => 'GPS row was not acknowledged by persistence',
                ];
            }
        }

        if ($failed !== []) {
            Log::error('IngestGpsData: row persistence incomplete; job remains retryable', [
                'failed_rows' => $failed,
                'written_rows' => $written,
            ]);
            throw new \RuntimeException('GPS row persistence failed; '.count($failed).' row(s) remain retryable');
        }

        return $written;
    }

    private function isDuplicateKeyException(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate') || str_contains($message, '1062');
    }

    private function rowAlreadyPersisted($gps, array $row): bool
    {
        try {
            $existing = $gps->table('gps_data')
                ->select(['coordinate'])
                ->where('imei', (string) ($row['imei'] ?? ''))
                ->where('date_time', (string) ($row['date_time'] ?? ''))
                ->get();

            $key = $this->normalizedCoordinateKey($row['coordinate'] ?? null);
            foreach ($existing as $candidate) {
                if ($this->normalizedCoordinateKey($candidate->coordinate) === $key) {
                    return true;
                }
            }
        } catch (Throwable) {
            // The caller will retry the row and eventually surface a DLQ state.
        }

        return false;
    }

    /**
     * Add tie-break metadata only when the additive migration is installed.
     * Older production schemas keep the exact existing insert shape.
     */
    private function gpsInsertRow(array $row): array
    {
        $eventId = $row['_event_id'] ?? null;
        $batchIndex = $row['_batch_index'] ?? null;
        unset($row['_event_id'], $row['_batch_index']);

        if ($this->hasGpsColumn('event_id') && $eventId !== null) {
            $row['event_id'] = (string) $eventId;
        }
        if ($this->hasGpsColumn('batch_index') && $batchIndex !== null) {
            $row['batch_index'] = (int) $batchIndex;
        }

        return $row;
    }

    private static array $gpsColumnExists = [];

    private function hasGpsColumn(string $column): bool
    {
        if (array_key_exists($column, self::$gpsColumnExists)) {
            return self::$gpsColumnExists[$column];
        }

        try {
            $database = (string) config('database.connections.mysql_gps.database');
            $row = DB::connection('mysql_gps')->selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
                [$database, 'gps_data', $column]
            );
            self::$gpsColumnExists[$column] = ((int) ($row->c ?? 0)) > 0;
        } catch (Throwable) {
            self::$gpsColumnExists[$column] = false;
        }

        return self::$gpsColumnExists[$column];
    }

    /**
     * Whitelist columns and coerce defaults. Invalid direct-job frames are
     * quarantined here; controller-originated frames are already item-validated.
     *
     * @return array<int, array<string, mixed>>
     */
    private function prepareBatch(array $data, int $tractorId, string $fallbackImei, array $sourceIndexes = []): array
    {
        $records = [];

        foreach ($data as $index => $item) {
            $sourceIndex = (int) ($sourceIndexes[$index] ?? $index);
            if (! is_array($item)) {
                $this->quarantineItem($item, $sourceIndex, 'GPS frame is not an array');
                Log::warning('IngestGpsData: invalid non-array GPS frame', [
                    'index' => $sourceIndex,
                    'tractor_id' => $tractorId,
                ]);

                continue;
            }

            $record = $this->sanitizeRow($item, $tractorId, $fallbackImei, $sourceIndex);
            if ($record !== null) {
                $event = $this->eventMetadataForIndex($sourceIndex, $item);
                $record['_event_id'] = $event['event_id'];
                $record['_batch_index'] = $event['batch_index'] ?? $sourceIndex;
                $records[] = $record;
            } else {
                $this->quarantineItem($item, $sourceIndex, 'GPS frame failed persistence validation');
            }
        }

        // Do not rewrite or stagger timestamps. Device time is the ordering key;
        // id is the stable tie-breaker at the historical API layer.
        return $records;
    }

    /**
     * Normalize a valid device timestamp without replacing it with server time.
     * Offline and out-of-order points are valid history and must remain replayable.
     */
    private function normalizeDeviceDateTime(string $raw, string $imei, int $index): ?string
    {
        if (trim($raw) === '') {
            Log::warning('IngestGpsData: empty date_time — item quarantined', [
                'imei' => $imei,
                'index' => $index,
            ]);

            return null;
        }

        try {
            $dt = Carbon::parse($raw);
        } catch (Throwable) {
            Log::warning('IngestGpsData: unparseable date_time — item quarantined', [
                'imei' => $imei,
                'index' => $index,
                'raw' => $raw,
            ]);

            return null;
        }

        return $dt->format('Y-m-d H:i:s');
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

        $imei = isset($item['imei']) && is_scalar($item['imei']) && trim((string) $item['imei']) !== ''
            ? trim((string) $item['imei'])
            : '';

        if ($imei === '') {
            Log::warning('IngestGpsData: GPS frame has no IMEI and cannot be mapped', [
                'index' => $index,
                'tractor_id' => $tractorId,
            ]);

            return null;
        }

        $dateTime = $item['date_time'] ?? null;
        if (! is_scalar($dateTime) || (string) $dateTime === '') {
            Log::warning('IngestGpsData: skipping GPS frame with missing date_time', [
                'index' => $index,
                'imei' => $imei,
                'tractor_id' => $tractorId,
            ]);

            return null;
        }

        $normalizedDateTime = $this->normalizeDeviceDateTime((string) $dateTime, $imei, $index);
        if ($normalizedDateTime === null) {
            return null;
        }

        return [
            'tractor_id' => $tractorId,
            'coordinate' => $coordinate,
            'speed' => (int) ($item['speed'] ?? 0),
            'status' => (int) ($item['status'] ?? 0),
            'directions' => $directions,
            'imei' => $imei,
            'date_time' => $normalizedDateTime,
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
