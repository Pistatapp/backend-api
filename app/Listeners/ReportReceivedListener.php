<?php

namespace App\Listeners;

use App\Events\ReportReceived;
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

        // Update task status based on zone entry/exit (passes zone info to event)
        $this->updateTaskStatus($currentTask, $isInZone);
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
            // Zone info will be passed through markTaskInProgressIfApplicable
            $this->tractorTaskStatusService->markTaskInProgressIfApplicable($task);
        } else {
            // Tractor exited zone - mark as stopped if applicable
            // Zone info will be passed through markTaskStoppedIfApplicable
            $this->tractorTaskStatusService->markTaskStoppedIfApplicable($task);
        }
    }
}
