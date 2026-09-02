<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Http\Requests\GpsReportRequest;
use App\Jobs\IngestGpsData;
use App\Services\GpsIngressLedger;
use App\Services\NocMonitor;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class GpsReportController extends Controller
{
    public function __invoke(GpsReportRequest $request): JsonResponse
    {
        $traceId = $this->extractTraceId($request);

        if (config('services.gps_ingest.driver') === 'go') {
            return $this->delegateToGoService($request, $traceId);
        }

        $receivedAt = $this->extractGatewayReceivedAt($request) ?? now()->format('Y-m-d H:i:s');
        $validData = [];
        $eventMetadata = [];
        $invalidMessages = [];
        $ledger = app(GpsIngressLedger::class);

        foreach ($request->gpsIngressItems() as $item) {
            $index = (int) ($item['index'] ?? 0);
            $rawItem = (string) ($item['raw_payload'] ?? '');
            $candidate = $item['data'] ?? null;
            $metadata = $this->makeEventMetadata($candidate, $rawItem, $index, $traceId, $receivedAt);

            if (! is_array($candidate)) {
                $reason = (string) ($item['error_reason'] ?? 'Invalid GPS item');
                $ledger->quarantine($metadata, $reason);
                $invalidMessages["data.$index"] = $reason;
                continue;
            }

            $validator = Validator::make($candidate, $request->itemRules());
            if ($validator->fails()) {
                $reason = json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    ?: 'GPS item validation failed';
                $ledger->quarantine($metadata, $reason);
                $invalidMessages["data.$index"] = $reason;
                continue;
            }

            $metadata['_job_index'] = count($validData);
            $validData[] = $candidate;
            $eventMetadata[] = $metadata;
        }

        if ($validData === []) {
            if ($invalidMessages !== []) {
                throw ValidationException::withMessages($invalidMessages);
            }

            throw ValidationException::withMessages(['data' => 'GPS data must contain at least one item.']);
        }

        $imei = (string) ($validData[0]['imei'] ?? 'unknown');

        try {
            // Ledger first: a queue push is not an event audit record. The
            // existing response remains {success:true} for valid requests.
            $ledger->recordPending($eventMetadata);
            $this->dispatchIngestWithRetry($validData, $traceId, $eventMetadata, $receivedAt);
            NocMonitor::emit(
                'PISTAT_DELIVERY',
                'success',
                $imei,
                $traceId,
                'PiStat queued IngestGpsData',
                ['phase' => 'queued', 'records' => count($validData), 'quarantined' => count($invalidMessages)]
            );
        } catch (Throwable $e) {
            Log::error('GPS ingest dispatch failed', [
                'imei' => $validData[0]['imei'] ?? null,
                'record_count' => count($validData),
                'error' => $e->getMessage(),
            ]);
            foreach ($eventMetadata as $event) {
                $ledger->mark((string) $event['event_id'], GpsIngressLedger::DLQ_REPLAYABLE, $e->getMessage());
            }
            NocMonitor::emit(
                'PISTAT_DELIVERY',
                'error',
                $imei,
                $traceId,
                'PiStat dispatch failed',
                ['phase' => 'queued'],
                $e->getMessage()
            );

            throw $e;
        }

        return response()->json(['success' => true], 200);
    }

    /**
     * Redis may briefly return LOADING after restart; retry then optional sync fallback
     * so gateway packets are not dropped while workers/redis recover.
     *
     * @param  array<int, array<string, mixed>>  $data
     * @param  array<int, array<string, mixed>>  $eventMetadata
     */
    private function dispatchIngestWithRetry(
        array $data,
        ?string $traceId,
        array $eventMetadata,
        string $gatewayReceivedAt
    ): void
    {
        $attempts = 3;
        $last = null;

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                IngestGpsData::dispatch($data, $traceId, $eventMetadata, $gatewayReceivedAt);

                return;
            } catch (Throwable $e) {
                $last = $e;
                if (! $this->isTransientRedisError($e) || $i === $attempts) {
                    break;
                }
                usleep(200_000 * $i);
            }
        }

        if ($last && $this->isTransientRedisError($last) && config('services.gps_ingest.sync_fallback', true)) {
            Log::warning('GPS ingest Redis unavailable — running IngestGpsData synchronously', [
                'error' => $last->getMessage(),
                'records' => count($data),
            ]);
            (new IngestGpsData($data, $traceId, $eventMetadata, $gatewayReceivedAt))->handle();

            return;
        }

        throw $last ?? new \RuntimeException('GPS ingest dispatch failed');
    }

    /**
     * @param array<string,mixed>|null $candidate
     * @return array<string,mixed>
     */
    private function makeEventMetadata(
        ?array $candidate,
        string $rawItem,
        int $index,
        ?string $traceId,
        string $gatewayReceivedAt
    ): array {
        $imei = is_array($candidate) && isset($candidate['imei']) && is_scalar($candidate['imei'])
            ? (string) $candidate['imei']
            : null;
        $deviceRecordedAt = is_array($candidate)
            ? ($candidate['device_recorded_at'] ?? $candidate['date_time'] ?? null)
            : null;
        $deviceRecordedAt = is_scalar($deviceRecordedAt) ? (string) $deviceRecordedAt : null;
        $payloadHash = hash('sha256', $rawItem);
        $providedEventId = is_array($candidate)
            ? ($candidate['event_id'] ?? $candidate['eventId'] ?? $candidate['eventID'] ?? null)
            : null;
        $eventId = is_scalar($providedEventId) && trim((string) $providedEventId) !== ''
            ? substr(trim((string) $providedEventId), 0, 64)
            : hash('sha256', ($imei ?? '').'|'.($deviceRecordedAt ?? '').'|'.$payloadHash.'|'.$index);

        if ($deviceRecordedAt !== null) {
            try {
                $deviceRecordedAt = Carbon::parse($deviceRecordedAt)->format('Y-m-d H:i:s');
            } catch (Throwable) {
                $deviceRecordedAt = null;
            }
        }

        return [
            'event_id' => $eventId,
            'trace_id' => $traceId,
            'imei' => $imei,
            'device_recorded_at' => $deviceRecordedAt,
            'gateway_received_at' => $gatewayReceivedAt,
            'payload_hash' => $payloadHash,
            'raw_payload' => $rawItem,
            'batch_index' => $index,
            'attempts' => 0,
        ];
    }

    private function isTransientRedisError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'loading')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'read error')
            || str_contains($message, 'went away');
    }

    private function extractTraceId(GpsReportRequest $request): ?string
    {
        foreach (['X-Trace-Id', 'X-Trace-ID', 'Trace-Id'] as $h) {
            $v = trim((string) $request->header($h, ''));
            if ($v !== '') {
                return $v;
            }
        }

        return null;
    }

    private function extractGatewayReceivedAt(GpsReportRequest $request): ?string
    {
        foreach (['X-Gateway-Received-At', 'X-Gateway-Received', 'Gateway-Received-At'] as $header) {
            $value = trim((string) $request->header($header, ''));
            if ($value === '') {
                continue;
            }

            try {
                return Carbon::parse($value)->format('Y-m-d H:i:s');
            } catch (Throwable) {
                Log::warning('GPS ingress supplied an invalid gateway received timestamp', [
                    'header' => $header,
                    'value' => $value,
                ]);
            }
        }

        return null;
    }

    private function delegateToGoService(GpsReportRequest $request, ?string $traceId): JsonResponse
    {
        $url = rtrim((string) config('services.gps_ingest.go_url'), '/').'/api/gps/reports';

        $headers = [
            'X-Real-IP' => $request->ip(),
            'X-Forwarded-For' => $request->ip(),
        ];
        if ($traceId) {
            $headers['X-Trace-Id'] = $traceId;
        }

        $response = Http::timeout(5)
            ->withHeaders($headers)
            ->post($url, $request->all());

        return response()->json(
            $response->json() ?? ['success' => $response->successful()],
            $response->status()
        );
    }
}
