<?php

namespace App\Jobs;

use App\Events\ReportReceived;
use App\Events\TractorStatus;
use App\Models\GpsDevice;
use App\Models\Tractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BroadcastGpsEvents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public array $data,
        public int $tractorId,
        public string $deviceImei,
    ) {
        $this->onConnection('redis');
        $this->onQueue('gps-broadcast');
    }

    public function handle(): void
    {
        $tractor = Tractor::with('gpsDevice')->find($this->tractorId);

        if (! $tractor) {
            return;
        }

        $device = $tractor->gpsDevice ?? GpsDevice::where('imei', $this->deviceImei)->first();

        if (! $device) {
            return;
        }

        $tractor->setRelation('gpsDevice', $device);
        $device->setRelation('tractor', $tractor);

        $lastPoint = end($this->data);
        $lastStatus = (int) $lastPoint['status'];

        UpdateTractorStatusJob::dispatch($this->tractorId, $lastStatus);
        event(new TractorStatus($tractor, $lastStatus));
        event(new ReportReceived([$lastPoint], $device));
    }
}
