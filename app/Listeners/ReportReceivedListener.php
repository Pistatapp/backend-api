<?php

namespace App\Listeners;

use App\Events\ReportReceived;
use App\Services\TractorTaskService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

class ReportReceivedListener implements ShouldQueue, ShouldQueueAfterCommit
{
    public string $connection = 'redis';

    public string $queue = 'gps-side-effects';

    public int $tries = 3;

    public function __construct(
        private TractorTaskService $tractorTaskService,
    ) {
        //
    }

    public function handle(ReportReceived $event): void
    {
        $event->device->loadMissing('tractor');
        $tractor = $event->device->tractor;

        if (! $tractor) {
            return;
        }

        $points = $event->points;
        if (empty($points)) {
            return;
        }

        $lastPoint = end($points);

        $dateTime = $lastPoint['date_time'] ?? null;
        if (! $dateTime) {
            return;
        }

        if (! $dateTime instanceof Carbon) {
            $dateTime = Carbon::parse($dateTime);
        }

        $currentTask = $this->tractorTaskService->getCurrentTask($dateTime, $tractor);

        if (! $currentTask) {
            return;
        }

        $coordinate = $lastPoint['coordinate'];

        $isInTaskZone = $this->tractorTaskService->isPointInTaskZone($coordinate, $currentTask);

        $this->tractorTaskService->updateTaskStatus($currentTask, $isInTaskZone, $dateTime);
    }
}
