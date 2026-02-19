<?php

namespace App\Jobs;

use App\Events\ReportReceived;
use App\Events\TractorStatus;
use App\Models\Tractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BroadcastGpsEvents implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public int $timeout = 15;

    public array $backoff = [2, 5, 10];

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public array $data,
        public int $tractorId,
        public string $deviceImei,
    ) {
        $this->onQueue('gps-events');
    }

    public function handle(): void
    {
        if (empty($this->data)) {
            return;
        }

        $tractor = $this->resolveTractor();

        if ($tractor === null) {
            Log::warning('BroadcastGpsEvents: tractor not found', [
                'tractor_id' => $this->tractorId,
            ]);
            return;
        }

        $lastStatus = end($this->data)['status'];
        event(new TractorStatus($tractor, $lastStatus));

        $device = $tractor->gpsDevice;
        if ($device !== null) {
            event(new ReportReceived($this->data, $device));
        }
    }

    private function resolveTractor(): ?Tractor
    {
        return Cache::remember(
            "tractor_by_device_imei_{$this->deviceImei}",
            3600,
            fn () => Tractor::whereHas('gpsDevice', function ($query) {
                $query->where('imei', $this->deviceImei);
            })->with('gpsDevice')->first()
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('BroadcastGpsEvents failed', [
            'tractor_id' => $this->tractorId,
            'imei' => $this->deviceImei,
            'error' => $exception->getMessage(),
        ]);
    }
}
