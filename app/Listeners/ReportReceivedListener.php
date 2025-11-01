<?php

namespace App\Listeners;

use App\Events\ReportReceived;
use App\Events\TractorZoneStatus;
use App\Services\TractorTaskService;
use App\Services\TractorTaskStatusService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ReportReceivedListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private TractorTaskService $tractorTaskService,
        private TractorTaskStatusService $tractorTaskStatusService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(ReportReceived $event): void
    {
        try {
            $device = $event->device;
            $points = $event->points;

            if (!$device->tractor) {
                return;
            }

            $tractor = $device->tractor;

            // Process each GPS point
            foreach ($points as $point) {
                $this->processGpsPoint($tractor, $point);
            }

        } catch (\Exception $e) {
            Log::error('Error processing GPS report', [
                'error' => $e->getMessage(),
                'device_id' => $event->device->id ?? null,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Process a single GPS point for task detection.
     *
     * @param \App\Models\Tractor $tractor
     * @param array $point
     */
    private function processGpsPoint($tractor, array $point): void
    {
        $timestamp = Carbon::parse($point['date_time']);
        $coordinate = $point['coordinate'];

        // Find current task
        $currentTask = $this->tractorTaskService->getCurrentTask($timestamp, $tractor);

        if (!$currentTask) {
            return;
        }

        // Check if point is in task zone
        $isInZone = $this->tractorTaskService->isPointInTaskZone($coordinate, $currentTask);

        // Update task status based on zone entry/exit
        $this->updateTaskStatus($currentTask, $isInZone);

        // Fire zone status event
        $this->fireZoneStatusEvent($tractor->gpsDevice, $currentTask, $isInZone);
    }

    /**
     * Update task status based on zone entry/exit.
     *
     * @param \App\Models\TractorTask $task
     * @param bool $isInZone
     */
    private function updateTaskStatus($task, bool $isInZone): void
    {
        if ($isInZone) {
            // Tractor entered zone - mark as in_progress if applicable
            $this->tractorTaskStatusService->markTaskInProgressIfApplicable($task);
        } else {
            // Tractor exited zone - mark as stopped if applicable
            $this->tractorTaskStatusService->markTaskStoppedIfApplicable($task);
        }
    }

    /**
     * Fire TractorZoneStatus event.
     *
     * @param \App\Models\GpsDevice $device
     * @param \App\Models\TractorTask $task
     * @param bool $isInZone
     */
    private function fireZoneStatusEvent($device, $task, bool $isInZone): void
    {
        event(new TractorZoneStatus(
            [
                'is_in_task_zone' => $isInZone,
                'task_id' => $task->id,
                'task_name' => $task->operation?->name ?? 'Unknown Task',
            ],
            $device
        ));
    }
}
