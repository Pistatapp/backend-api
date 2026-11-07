<?php

namespace App\Jobs;

use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Services\GpsIngressLedger;
use App\Services\NocMonitor;
use App\Support\GpsDeviceCache;
use App\Support\GpsTimestamp;
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
     * Historical recovery uses the same transactional persistence path but
     * must never enqueue a live GPS broadcast for an old event.
     */
    public bool $emitLiveBroadcast = true;

    public function __construct(
        public array $data,
        public ?string $traceId = null,
        public array $eventMetadata = [],
        public ?string $gatewayReceivedAt = null,
        bool $emitLiveBroadcast = true,
    ) {
        $this->emitLiveBroadcast = $emitLiveBroadcast;
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

            // Only broadcast natural/live ingestion after durable write. A
            // bounded historical recovery deliberately persists through this
            // same path without emitting a live GPS event.
            if ($this->emitLiveBroadcast) {
                BroadcastGpsEvents::dispatch(
                    array_column($group, 'item'),
                    $tractor->id,
                    $deviceImei,
                    $this->traceId
                );
            }
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
            'device_recorded_at' => GpsTimestamp::normalizeToUtc(
                $dateTime,
                config('app.timezone', 'Asia/Tehran'),
            ),
            'gateway_received_at' => $this->gatewayReceivedAt ?? GpsTimestamp::nowUtc(),
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
                    $this->persistGatewayTimeProvenance(
                        $chunk,
                        (string) ($chunk[0]['imei'] ?? ''),
                        (int) ($chunk[0]['tractor_id'] ?? 0),
                        $gps
                    );
                    $this->recordIstartekIdentityEvidence($gps, $chunk);
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
            $code = trim((string) ($row['_gps_log_code'] ?? ''));
            if (str_starts_with($code, '01-')) {
                // Same-second iStartek events with different effective payload
                // remain visible to the identity ledger as conflicts.
                $key .= '|'.$code.'|'.((string) ($row['_payload_hash'] ?? ''));
            }
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

        $columns = ['id', 'imei', 'date_time', 'coordinate'];
        if ($this->hasGpsColumn('gps_log_code')) {
            $columns[] = 'gps_log_code';
        }
        $existing = $gps->table('gps_data')
            ->select($columns)
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
                    $incomingCode = trim((string) ($row['_gps_log_code'] ?? ''));
                    if (str_starts_with($incomingCode, '01-')
                        && $this->istartekIdentityTablesExist($gps)
                        && (string) ($match->gps_log_code ?? '') !== $incomingCode) {
                        continue;
                    }
                    if (str_starts_with($incomingCode, '01-')
                        && $this->istartekIdentityTablesExist($gps)
                        && ! $this->sameIstartekPayloadHash($gps, $incomingCode, (string) ($row['_payload_hash'] ?? ''))) {
                        continue;
                    }
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
                    $deltaWritten = $gps->transaction(function () use ($gps, $row): int {
                        $deltaWritten = $this->persistChunkWithDelta($gps, [$row]);
                        $this->persistGatewayTimeProvenance(
                            [$row],
                            (string) ($row['imei'] ?? ''),
                            (int) ($row['tractor_id'] ?? 0),
                            $gps
                        );
                        $this->recordIstartekIdentityEvidence($gps, [$row]);

                        return $deltaWritten;
                    });
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
        $gpsLogCode = $row['_gps_log_code'] ?? null;
        $rawIngressId = $row['_raw_ingress_id'] ?? null;
        unset(
            $row['_event_id'],
            $row['_batch_index'],
            $row['_gps_log_code'],
            $row['_raw_ingress_id'],
            $row['_payload_hash'],
            $row['_source_protocol'],
            $row['_event_time_utc'],
            $row['_event_date_jalali'],
            $row['_event_time_tehran'],
            $row['_event_time_tehran_clock'],
            $row['_timezone'],
            $row['_time_provenance'],
            $row['_time_source'],
            $row['_other_original_gps_fields'],
        );

        if ($this->hasGpsColumn('event_id') && $eventId !== null) {
            $row['event_id'] = (string) $eventId;
        }
        if ($this->hasGpsColumn('batch_index') && $batchIndex !== null) {
            $row['batch_index'] = (int) $batchIndex;
        }
        if ($this->hasGpsColumn('gps_log_code') && is_scalar($gpsLogCode) && trim((string) $gpsLogCode) !== '') {
            $row['gps_log_code'] = trim((string) $gpsLogCode);
        }
        if ($this->hasGpsColumn('raw_ingress_id') && is_scalar($rawIngressId) && trim((string) $rawIngressId) !== '') {
            $row['raw_ingress_id'] = trim((string) $rawIngressId);
        }

        return $row;
    }

    /**
     * Store the Gateway-owned iStartek time contract beside the legacy GPS row.
     * No Jalali or Tehran value is calculated here.
     *
     * @param  array<int, array<string, mixed>>  $records
     */
    private function persistGatewayTimeProvenance(array $records, string $imei, int $tractorId, $gps = null): void
    {
        $istartekRecords = array_values(array_filter(
            $records,
            static function (array $row): bool {
                $sourceProtocol = strtolower(trim((string) ($row['_source_protocol'] ?? '')));
                $eventId = strtolower(trim((string) ($row['_event_id'] ?? '')));

                return $sourceProtocol === 'istartek' || str_starts_with($eventId, 'ist-');
            }
        ));
        if ($istartekRecords === []) {
            return;
        }

        $gps ??= DB::connection('mysql_gps');
        $database = (string) config('database.connections.mysql_gps.database');
        $hasTable = (int) ($gps->selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            [$database, 'gps_event_time_provenance']
        )->c ?? 0) > 0;
        $hasEventAuditTable = (int) ($gps->selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            [$database, 'gps_event_time_provenance_events']
        )->c ?? 0) > 0;
        if (! $hasTable || ! $hasEventAuditTable) {
            throw new \RuntimeException('iStartek provenance table is not installed');
        }

        $now = now('UTC')->format('Y-m-d H:i:s');
        $batchId = $this->provenanceBatchId($istartekRecords, $imei);
        foreach ($istartekRecords as $row) {
            $eventId = trim((string) ($row['_event_id'] ?? ''));
            $eventTimeUTC = trim((string) ($row['_event_time_utc'] ?? ''));
            $eventDateJalali = trim((string) ($row['_event_date_jalali'] ?? ''));
            $eventTimeTehran = trim((string) ($row['_event_time_tehran'] ?? ''));
            $eventTimeTehranClock = trim((string) ($row['_event_time_tehran_clock'] ?? ''));
            $timezone = trim((string) ($row['_timezone'] ?? ''));
            $otherOriginalFields = $row['_other_original_gps_fields'] ?? [];
            if ($eventId === '' || $eventTimeUTC === '' || $eventDateJalali === '' || $eventTimeTehran === '' || $timezone === '') {
                throw new \RuntimeException('iStartek row is missing Gateway time contract fields');
            }

            try {
                $storedUTC = Carbon::parse($eventTimeUTC)->utc()->format('Y-m-d H:i:s.u');
            } catch (Throwable $e) {
                throw new \RuntimeException('iStartek event_time_utc is invalid', 0, $e);
            }

            if ($eventTimeTehranClock === '') {
                try {
                    $eventTimeTehranClock = Carbon::parse($eventTimeTehran, 'Asia/Tehran')->format('H:i:s');
                } catch (Throwable) {
                    $eventTimeTehranClock = null;
                }
            }

            $candidates = $gps->table('gps_data')
                ->select(['id', 'coordinate'])
                ->where('tractor_id', $tractorId)
                ->where('imei', $imei)
                ->where('date_time', (string) $row['date_time'])
                ->orderByDesc('id')
                ->get();
            $coordinateKey = $this->normalizedCoordinateKey($row['coordinate'] ?? null);
            $gpsDataId = null;
            foreach ($candidates as $candidate) {
                if ($this->normalizedCoordinateKey($candidate->coordinate) === $coordinateKey) {
                    $gpsDataId = (int) $candidate->id;
                    break;
                }
            }
            if ($gpsDataId === null) {
                throw new \RuntimeException('Persisted iStartek GPS row could not be resolved for provenance');
            }

            $auditValues = [
                'gps_data_id' => $gpsDataId,
                'gps_date_time' => (string) $row['date_time'],
                'tractor_id' => $tractorId,
                'imei' => $imei,
                'source_protocol' => 'istartek',
                'event_time_utc' => $storedUTC,
                // These civil fields are supplied by the Gateway; PiStat does
                // not derive Tehran/Jalali values from UTC.
                'event_time_tehran' => (string) $row['date_time'],
                'event_date_jalali' => $eventDateJalali,
                'event_time_tehran_clock' => $eventTimeTehranClock,
                'timezone' => $timezone,
                'other_original_gps_fields' => json_encode(
                    is_array($otherOriginalFields) ? $otherOriginalFields : [],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                ),
                'gateway_received_at_utc' => $this->gatewayReceivedAt !== null
                    ? GpsTimestamp::normalizeToUtc($this->gatewayReceivedAt)
                    : null,
                'time_source' => trim((string) ($row['_time_source'] ?? '')) !== ''
                    ? trim((string) $row['_time_source'])
                    : 'gateway',
                'time_provenance' => trim((string) ($row['_time_provenance'] ?? '')) !== ''
                    ? trim((string) $row['_time_provenance'])
                    : 'protocol_verified_utc',
                'verification_status' => 'verified',
                'updated_at' => $now,
            ];
            if ($this->hasProvenanceColumn($gps, 'gps_event_time_provenance', 'batch_id')) {
                $auditValues['batch_id'] = $batchId;
            }
            $eventAuditValues = $auditValues + ['audit_status' => 'verified'];
            $gps->table('gps_event_time_provenance_events')->updateOrInsert(
                ['event_id' => substr($eventId, 0, 64)],
                $eventAuditValues + ['created_at' => $now]
            );

            $existing = $gps->table('gps_event_time_provenance')
                ->where('gps_data_id', $gpsDataId)
                ->where('gps_date_time', (string) $row['date_time'])
                ->first(['event_id']);
            // Keep one stable logical plotted point while retaining every
            // Gateway event_id in the event-audit table above.
            if ($existing === null || empty($existing->event_id)) {
                $gps->table('gps_event_time_provenance')->updateOrInsert(
                    ['gps_data_id' => $gpsDataId, 'gps_date_time' => (string) $row['date_time']],
                    $auditValues + ['event_id' => substr($eventId, 0, 64), 'created_at' => $now]
                );
            }
        }
    }

    private function provenanceBatchId(array $records, string $imei): string
    {
        if (trim((string) $this->traceId) !== '') {
            return substr('trace:'.trim((string) $this->traceId), 0, 128);
        }

        return 'ingest:'.hash('sha256', $imei.'|'.($records[0]['date_time'] ?? '').'|'.count($records));
    }

    private function hasProvenanceColumn($gps, string $table, string $column): bool
    {
        static $cache = [];
        $database = (string) config('database.connections.mysql_gps.database');
        $key = $database.'|'.$table.'|'.$column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        return $cache[$key] = (int) ($gps->selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            [$database, $table, $column]
        )->c ?? 0) > 0;
    }

    private static array $gpsColumnExists = [];

    private static ?bool $istartekIdentityTablesExist = null;

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

    private function sameIstartekPayloadHash($gps, string $code, string $payloadHash): bool
    {
        if ($payloadHash === '') {
            return false;
        }

        $identity = $gps->table('gps_log_identities')
            ->select('canonical_payload_hash')
            ->where('gps_log_code', $code)
            ->lockForUpdate()
            ->first();

        return $identity !== null
            && (string) ($identity->canonical_payload_hash ?? '') !== ''
            && hash_equals((string) $identity->canonical_payload_hash, $payloadHash);
    }

    /**
     * Record append-only logical identity and raw-receipt evidence for the
     * owner-approved iStartek code only. This runs in the same mysql_gps
     * transaction as gps_data persistence and never hides a GPS row.
     *
     * @param  array<int, array<string,mixed>>  $chunk
     */
    private function recordIstartekIdentityEvidence($gps, array $chunk): void
    {
        $rows = array_values(array_filter($chunk, function (array $row): bool {
            return str_starts_with(trim((string) ($row['_gps_log_code'] ?? '')), '01-');
        }));

        if ($rows === [] || ! $this->istartekIdentityTablesExist($gps)) {
            return;
        }

        foreach ($rows as $row) {
            $code = trim((string) ($row['_gps_log_code'] ?? ''));
            $imei = trim((string) ($row['imei'] ?? ''));
            $eventId = trim((string) ($row['_event_id'] ?? ''));
            $rawIngressId = trim((string) ($row['_raw_ingress_id'] ?? ''));
            $payloadHash = trim((string) ($row['_payload_hash'] ?? ''));
            if ($payloadHash === '') {
                $payloadHash = hash('sha256', json_encode([
                    $imei,
                    $code,
                    $row['coordinate'] ?? null,
                    $row['speed'] ?? null,
                    $row['status'] ?? null,
                    $row['directions'] ?? null,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            if (! preg_match('/^01-([0-9]{14,20})-([0-9]{14})$/', $code, $codeParts)
                || $codeParts[1] !== $imei) {
                continue;
            }

            $identity = $gps->table('gps_log_identities')
                ->where('gps_log_code', $code)
                ->lockForUpdate()
                ->first();

            $status = 'FIRST';
            $conflictReason = null;
            if ($identity === null) {
                try {
                    $deviceUtc = Carbon::createFromFormat('YmdHis', $codeParts[2], 'UTC');
                } catch (Throwable) {
                    continue;
                }
                if ($deviceUtc === false) {
                    continue;
                }
                $gps->table('gps_log_identities')->insert([
                    'gps_log_code' => $code,
                    'vendor' => '01',
                    'imei' => $imei,
                    'device_event_utc' => $deviceUtc->format('Y-m-d H:i:s.u'),
                    'existing_event_id' => $eventId !== '' ? $eventId : null,
                    'canonical_payload_hash' => $payloadHash,
                    'status' => 'PERSISTED',
                    'first_seen_at' => now('UTC'),
                    'last_seen_at' => now('UTC'),
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);

                if ($this->hasGpsColumn('gps_log_code')) {
                    $canonical = $gps->table('gps_data')
                        ->select('id')
                        ->where('gps_log_code', $code)
                        ->orderBy('id')
                        ->first();
                    if ($canonical !== null) {
                        $gps->table('gps_log_identities')
                            ->where('gps_log_code', $code)
                            ->update(['canonical_gps_data_id' => $canonical->id]);
                    }
                }
            } elseif ((string) ($identity->canonical_payload_hash ?? '') !== ''
                && ! hash_equals((string) $identity->canonical_payload_hash, $payloadHash)) {
                $status = 'SAME_SECOND_CONFLICT';
                $conflictReason = 'payload differs for one IMEI and device UTC second';
                $gps->table('gps_log_identities')
                    ->where('gps_log_code', $code)
                    ->update([
                        'status' => $status,
                        'last_seen_at' => now('UTC'),
                        'updated_at' => now('UTC'),
                    ]);
            } else {
                $status = 'RETRANSMISSION';
                $gps->table('gps_log_identities')
                    ->where('gps_log_code', $code)
                    ->update(['last_seen_at' => now('UTC'), 'updated_at' => now('UTC')]);
            }

            if ($rawIngressId === '') {
                continue;
            }

            $gps->table('gps_log_receipts')->upsert([[
                'raw_ingress_id' => $rawIngressId,
                'gps_log_code' => $code,
                'existing_event_id' => $eventId !== '' ? $eventId : null,
                'payload_hash' => substr($payloadHash, 0, 64),
                'deduplication_status' => $status,
                'conflict_reason' => $conflictReason,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]], ['raw_ingress_id'], [
                'gps_log_code',
                'existing_event_id',
                'payload_hash',
                'deduplication_status',
                'conflict_reason',
                'updated_at',
            ]);
        }
    }

    private function istartekIdentityTablesExist($gps): bool
    {
        if (self::$istartekIdentityTablesExist !== null) {
            return self::$istartekIdentityTablesExist;
        }

        try {
            $database = (string) config('database.connections.mysql_gps.database');
            $count = $gps->selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ? AND table_name IN (?, ?)',
                [$database, 'gps_log_identities', 'gps_log_receipts']
            );
            self::$istartekIdentityTablesExist = ((int) ($count->c ?? 0)) === 2;
        } catch (Throwable) {
            self::$istartekIdentityTablesExist = false;
        }

        return self::$istartekIdentityTablesExist;
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

        $record = [
            'tractor_id' => $tractorId,
            'coordinate' => $coordinate,
            'speed' => (int) ($item['speed'] ?? 0),
            'status' => (int) ($item['status'] ?? 0),
            'directions' => $directions,
            'imei' => $imei,
            'date_time' => $normalizedDateTime,
        ];

        $sourceProtocol = strtolower(trim((string) ($item['source_protocol'] ?? $item['protocol'] ?? '')));
        $eventId = is_scalar($item['event_id'] ?? null) ? trim((string) $item['event_id']) : '';
        $isIstartek = $sourceProtocol === 'istartek'
            || str_starts_with(strtolower($eventId), 'ist-');
        if ($isIstartek) {
            $record['_source_protocol'] = 'istartek';
            $record['_gps_log_code'] = is_scalar($item['gps_log_code'] ?? null)
                ? trim((string) $item['gps_log_code'])
                : '';
            $record['_raw_ingress_id'] = is_scalar($item['raw_ingress_id'] ?? null)
                ? trim((string) $item['raw_ingress_id'])
                : '';
            $record['_payload_hash'] = is_scalar($item['payload_hash'] ?? ($item['raw_payload_hash'] ?? null))
                ? trim((string) ($item['payload_hash'] ?? $item['raw_payload_hash']))
                : '';
            $record['_event_time_utc'] = is_scalar($item['event_time_utc'] ?? null)
                ? trim((string) $item['event_time_utc'])
                : '';
            $record['_event_date_jalali'] = is_scalar($item['event_date_jalali'] ?? null)
                ? trim((string) $item['event_date_jalali'])
                : '';
            $record['_event_time_tehran'] = is_scalar($item['event_time_tehran'] ?? null)
                ? trim((string) $item['event_time_tehran'])
                : '';
            $record['_timezone'] = is_scalar($item['timezone'] ?? null)
                ? trim((string) $item['timezone'])
                : '';
            $record['_time_provenance'] = is_scalar($item['time_provenance'] ?? null)
                ? trim((string) $item['time_provenance'])
                : '';
            $record['_time_source'] = is_scalar($item['time_source'] ?? null)
                ? trim((string) $item['time_source'])
                : '';
            $record['_other_original_gps_fields'] = is_array($item['other_original_gps_fields'] ?? null)
                ? $item['other_original_gps_fields']
                : [];

            $missingContractFields = [];
            foreach (['_event_id' => $eventId, '_event_time_utc' => $record['_event_time_utc'], '_event_date_jalali' => $record['_event_date_jalali'], '_event_time_tehran' => $record['_event_time_tehran'], '_timezone' => $record['_timezone']] as $field => $value) {
                if ($value === '') {
                    $missingContractFields[] = $field;
                }
            }
            if ($missingContractFields !== []) {
                Log::warning('IngestGpsData: quarantining iStartek frame with incomplete time contract', [
                    'index' => $index,
                    'imei' => $imei,
                    'tractor_id' => $tractorId,
                    'missing_fields' => $missingContractFields,
                ]);

                return null;
            }
        }

        return $record;
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
