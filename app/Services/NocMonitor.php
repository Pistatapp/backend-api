<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * Side-channel NOC publisher (EVENT_CONTRACT.md).
 * Never mutates GPS ingest payloads — only emits monitor metadata.
 */
class NocMonitor
{
    public static function emit(
        string $stage,
        string $status,
        string $deviceId,
        ?string $traceId = null,
        string $message = '',
        array $payload = [],
        ?string $errorReason = null,
    ): void {
        if (! filter_var(config('services.noc_monitor.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $traceId = trim((string) $traceId);
        if ($traceId === '') {
            $traceId = (string) Str::uuid();
        }
        if ($deviceId === '') {
            $deviceId = 'unknown';
        }

        $event = [
            'eventId' => (string) Str::uuid(),
            'traceId' => $traceId,
            'deviceId' => $deviceId,
            'deviceType' => 'hooshtrack',
            'protocol' => 'hooshtrack_http',
            'stage' => $stage,
            'status' => $status,
            'timestamp' => (int) floor(microtime(true) * 1000),
            'message' => $message !== '' ? $message : $stage,
            'payload' => array_merge(['target' => 'pistat'], $payload),
        ];
        if ($errorReason) {
            $event['errorReason'] = $errorReason;
        }

        try {
            if (config('services.noc_monitor.driver', 'http') === 'redis') {
                self::publishRedis($event);

                return;
            }
            self::publishHttp($event);
        } catch (Throwable $e) {
            // Never fail GPS ingest because of monitoring.
            Log::debug('NocMonitor emit failed', ['error' => $e->getMessage()]);
        }
    }

    private static function publishRedis(array $event): void
    {
        $channel = (string) config('services.noc_monitor.channel', 'gps:monitor:events');
        Redis::connection((string) config('services.noc_monitor.redis_connection', 'default'))
            ->publish($channel, json_encode($event, JSON_UNESCAPED_UNICODE));
    }

    private static function publishHttp(array $event): void
    {
        $url = rtrim((string) config('services.noc_monitor.url', ''), '/');
        if ($url === '') {
            return;
        }
        $req = Http::timeout(1.5)->connectTimeout(0.5)->acceptJson();
        $token = (string) config('services.noc_monitor.token', '');
        if ($token !== '') {
            $req = $req->withHeaders(['X-Monitor-Token' => $token]);
        }
        $req->post($url.'/api/monitor/events', $event);
    }
}
