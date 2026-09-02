<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Durable per-event audit ledger for the GPS ingress boundary.
 *
 * The table is additive and lives on the same write connection as gps_data.
 * Until that migration is installed, the append-only local spool is the safe
 * fallback: the request is never ACKed on the basis of a log-only side channel.
 */
class GpsIngressLedger
{
    public const PERSISTED = 'PERSISTED';
    public const RETRY_PENDING = 'RETRY_PENDING';
    public const QUARANTINED_WITH_RAW = 'QUARANTINED_WITH_RAW';
    public const DLQ_REPLAYABLE = 'DLQ_REPLAYABLE';

    /**
     * @param array<int, array<string,mixed>> $events
     */
    public function recordPending(array $events): void
    {
        foreach ($events as $event) {
            $this->write(array_merge($event, ['status' => self::RETRY_PENDING]));
        }
    }

    /**
     * @param array<string,mixed> $event
     */
    public function quarantine(array $event, string $reason): void
    {
        $this->write(array_merge($event, [
            'status' => self::QUARANTINED_WITH_RAW,
            'error_reason' => $reason,
        ]));
    }

    public function mark(string $eventId, string $status, ?string $reason = null): void
    {
        $payload = [
            'event_id' => $eventId,
            'status' => $status,
            'error_reason' => $reason,
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ];
        if ($status === self::PERSISTED) {
            $payload['persisted_at'] = now()->format('Y-m-d H:i:s');
        }

        try {
            $updated = DB::connection('mysql_gps')->table('gps_ingest_events')
                ->where('event_id', $eventId)
                ->update($payload);

            if ($updated < 1) {
                throw new \RuntimeException('GPS ingress ledger event not found: '.$eventId);
            }

            return;
        } catch (Throwable $e) {
            $this->appendFallback($payload, $e);
        }
    }

    /**
     * @param array<string,mixed> $event
     */
    private function write(array $event): void
    {
        $event['updated_at'] ??= now()->format('Y-m-d H:i:s');
        $event['created_at'] ??= $event['updated_at'];
        $event['attempts'] = (int) ($event['attempts'] ?? 0);
        $databaseEvent = $event;
        unset($databaseEvent['_job_index']);

        try {
            DB::connection('mysql_gps')->table('gps_ingest_events')->upsert(
                [$databaseEvent],
                ['event_id'],
                [
                    'trace_id',
                    'imei',
                    'device_recorded_at',
                    'gateway_received_at',
                    'payload_hash',
                    'raw_payload',
                    'batch_index',
                    'status',
                    'error_reason',
                    'attempts',
                    'persisted_at',
                    'updated_at',
                ]
            );
        } catch (Throwable $e) {
            $this->appendFallback($event, $e);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function appendFallback(array $payload, Throwable $error): void
    {
        $path = (string) config('services.gps_ingest.ledger_path', storage_path('app/gps-ingest-ledger.ndjson'));
        $directory = dirname($path);

        try {
            if (! is_dir($directory)) {
                mkdir($directory, 0770, true);
            }

            $line = json_encode([
                'ledger_version' => 1,
                'written_at' => now()->format('Y-m-d H:i:s.u'),
                'event' => $payload,
                'database_error' => $error->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            if ($line === false || file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException('Unable to append GPS ingress ledger spool');
            }
        } catch (Throwable $spoolError) {
            // This is deliberately visible and rethrown. The ingress controller
            // must return an error rather than ACK an event with no durable state.
            Log::critical('GPS ingress ledger unavailable; event cannot be acknowledged', [
                'event_id' => $payload['event_id'] ?? null,
                'status' => $payload['status'] ?? null,
                'database_error' => $error->getMessage(),
                'spool_error' => $spoolError->getMessage(),
            ]);

            throw $spoolError;
        }
    }
}
