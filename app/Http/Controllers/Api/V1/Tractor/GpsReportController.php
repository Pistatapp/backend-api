<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Http\Requests\GpsReportRequest;
use App\Jobs\IngestGpsData;
use App\Services\NocMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GpsReportController extends Controller
{
    public function __invoke(GpsReportRequest $request): JsonResponse
    {
        $traceId = $this->extractTraceId($request);

        if (config('services.gps_ingest.driver') === 'go') {
            return $this->delegateToGoService($request, $traceId);
        }

        $data = $request->validated('data');
        $imei = (string) ($data[0]['imei'] ?? 'unknown');

        try {
            $this->dispatchIngestWithRetry($data, $traceId);
            NocMonitor::emit(
                'PISTAT_DELIVERY',
                'success',
                $imei,
                $traceId,
                'PiStat queued IngestGpsData',
                ['phase' => 'queued', 'records' => count($data)]
            );
        } catch (Throwable $e) {
            Log::error('GPS ingest dispatch failed', [
                'imei' => $data[0]['imei'] ?? null,
                'record_count' => count($data),
                'error' => $e->getMessage(),
            ]);
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
     */
    private function dispatchIngestWithRetry(array $data, ?string $traceId): void
    {
        $attempts = 3;
        $last = null;

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                IngestGpsData::dispatch($data, $traceId);

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
            (new IngestGpsData($data, $traceId))->handle();

            return;
        }

        throw $last ?? new \RuntimeException('GPS ingest dispatch failed');
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
