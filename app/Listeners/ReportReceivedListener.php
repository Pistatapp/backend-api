<?php

namespace App\Listeners;

use App\Events\ReportReceived;
use App\Services\TractorTaskService;
use App\Services\TractorTaskStatusService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ReportReceivedListener
{
    /**
     * Create the event listener.
     */
    public function __construct(
        private TractorTaskService $tractorTaskService,
        private TractorTaskStatusService $tractorTaskStatusService,
    ) {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(ReportReceived $event): void
    {
        Log::info('ReportReceivedListener', ['event' => $event, 'message' => 'Report received']);
        // Get tractor from event GPS device property
        $tractor = $event->device->tractor;

        // If no tractor is associated with the device, exit early
        if (!$tractor) {
            return;
        }

        // Get the last point from the points array (since data might be in batch)
        $points = $event->points;
        if (empty($points)) {
            return;
        }

        $lastPoint = end($points);

        // Get the date_time from the last point
        $dateTime = $lastPoint['date_time'] ?? null;
        if (!$dateTime) {
            return;
        }

        // Ensure date_time is a Carbon instance
        if (!$dateTime instanceof Carbon) {
            $dateTime = Carbon::parse($dateTime);
        }

        // Get current task of the tractor at the timestamp of the last point
        $currentTask = $this->tractorTaskService->getCurrentTask($dateTime, $tractor);

        // If no current task, exit early
        if (!$currentTask) {
            return;
        }

        // Load taskable relationship if not already loaded (needed for zone check)
        if (!$currentTask->relationLoaded('taskable')) {
            $currentTask->load('taskable');
        }

        // Get the coordinate from the last point
        $coordinate = $lastPoint['coordinate'];

        // Determine whether the point is in task zone
        $isInTaskZone = $this->tractorTaskService->isPointInTaskZone($coordinate, $currentTask);

        // Call TractorTaskStatusService for task status updates
        $this->tractorTaskStatusService->updateTaskStatus($currentTask, $isInTaskZone);
    }
}
