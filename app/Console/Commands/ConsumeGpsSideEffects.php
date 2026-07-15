<?php

namespace App\Console\Commands;

use App\Events\ReportReceived;
use App\Listeners\ReportReceivedListener;
use App\Models\GpsDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class ConsumeGpsSideEffects extends Command
{
    protected $signature = 'gps:consume-side-effects {--timeout=5 : BLPOP timeout in seconds}';

    protected $description = 'Consume GPS side-effect messages published by the Go ingest service';

    public function handle(ReportReceivedListener $listener): int
    {
        $timeout = max(1, (int) $this->option('timeout'));

        $this->info('Consuming gps_side_effects_inbox...');

        while (true) {
            $result = Redis::blpop(['gps_side_effects_inbox'], $timeout);

            if ($result === null || $result === false) {
                continue;
            }

            $this->processPayload(json_decode($result[1] ?? '', true), $listener);
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function processPayload(?array $payload, ReportReceivedListener $listener): bool
    {
        if (! is_array($payload)) {
            $this->warn('Skipping invalid side-effect payload.');

            return false;
        }

        $deviceId = $payload['device_id'] ?? null;
        $lastPoint = $payload['last_point'] ?? null;

        if (! $deviceId || ! is_array($lastPoint)) {
            $this->warn('Skipping incomplete side-effect payload.');

            return false;
        }

        $device = GpsDevice::with('tractor')->find($deviceId);
        if (! $device || ! $device->tractor) {
            return false;
        }

        $listener->handle(new ReportReceived([$lastPoint], $device));

        return true;
    }
}
