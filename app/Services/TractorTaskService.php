<?php

namespace App\Services;

use App\Jobs\CalculateTaskGpsMetricsJob;
use App\Events\TractorTaskStatusChanged;
use App\Models\Tractor;
use App\Models\TractorTask;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class TractorTaskService
{
    /**
     * Get the current active task for a tractor at a given timestamp.
     *
     * Priority: in_progress > stopped
     *
     * @param Carbon $timestamp
     * @param Tractor $tractor
     * @return TractorTask|null
     */
    public function getCurrentTask(Carbon $timestamp, Tractor $tractor): ?TractorTask
    {
        $date = $timestamp->toDateString();

        $currentTask = Cache::remember("tractor_task_{$tractor->id}_{$date}", 60, function () use ($tractor, $date, $timestamp) {
            return TractorTask::where('tractor_id', $tractor->id)
                ->whereDate('date', $date)
                ->where('start_time', '<=', $timestamp->format('H:i:s'))
                ->where('end_time', '>=', $timestamp->format('H:i:s'))
                ->with(['taskableItems.taskable'])
                ->first();
        });

        return $currentTask;
    }

    /**
     * Zone polygons for the task (one ring per selected field/plot/etc.).
     *
     * @return array<int, array<mixed>>
     */
    public function getTaskZones(TractorTask $task): array
    {
        $task->loadMissing('taskableItems.taskable');

        $zones = [];
        foreach ($task->taskableItems as $item) {
            $model = $item->taskable;
            if ($model && ! empty($model->coordinates)) {
                $zones[] = $model->coordinates;
            }
        }

        return $zones;
    }

    /**
     * Check if a GPS point is within any of the task zones.
     *
     * @param array{0: float, 1: float} $point [latitude, longitude]
     */
    public function isPointInTaskZone(array $point, TractorTask $task): bool
    {
        foreach ($this->getTaskZones($task) as $zone) {
            if (is_point_in_polygon($point, $zone)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all tasks for a tractor on a specific date (including completed ones).
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return \Illuminate\Support\Collection
     */
    public function getAllTasksForDate(Tractor $tractor, Carbon $date): \Illuminate\Support\Collection
    {
        return TractorTask::where('tractor_id', $tractor->id)
            ->whereDate('date', $date)
            ->with(['taskableItems.taskable', 'operation'])
            ->latest()
            ->get();
    }

    /**
     * Update the status of a tractor task based on current conditions.
     *
     * @param TractorTask $task
     * @param bool|null $isCurrentlyInZone Optional parameter to indicate if tractor is currently in zone
     * @param Carbon|null $gpsTimestamp Optional GPS timestamp from the received point
     * @return void
     */
    public function updateTaskStatus(TractorTask $task, ?bool $isCurrentlyInZone = null, ?Carbon $gpsTimestamp = null): void
    {
        $newStatus = $this->determineTaskStatus($task, $isCurrentlyInZone, $gpsTimestamp);

        if ($newStatus === 'done' && ! $this->hasFinalMetrics($task)) {
            // The task may be finalized by a live GPS event. Run the historical
            // window calculation immediately so the final status and map state
            // are correct without waiting for a queue worker.
            CalculateTaskGpsMetricsJob::dispatchSync($task->fresh());

            return;
        }

        $task->update(['status' => $newStatus]);
        event(new TractorTaskStatusChanged($task, $newStatus, $isCurrentlyInZone));
    }

    private function hasFinalMetrics(TractorTask $task): bool
    {
        return $task->gpsMetricsCalculation()->exists();
    }

    /**
     * Determine what the task status should be based on current conditions.
     *
     * Status Logic:
     * - not_started: Task time has not started yet
     * - not_done: Task ended with less than five minutes in the selected zones
     * - in_progress: Task time started and tractor has entered the area
     * - stopped: Task time has not finished yet, but tractor is working outside task zone
     * - done: Task ended and historical GPS analysis confirms at least five minutes in a selected zone
     *
     * @param TractorTask $task
     * @param bool|null $isCurrentlyInZone Optional parameter to indicate if tractor is currently in zone
     * @param Carbon|null $gpsTimestamp Optional GPS timestamp from the received point
     * @return string
     */
    private function determineTaskStatus(TractorTask $task, ?bool $isCurrentlyInZone = null, ?Carbon $gpsTimestamp = null): string
    {
        // Use GPS timestamp if provided, otherwise fall back to current time
        $now = $gpsTimestamp ?? Carbon::now();

        $taskStartTime = $task->getStartDateTime();
        $taskEndTime = $task->getEndDateTime();

        // Scenario 1: Task time has not started
        if ($now->lt($taskStartTime)) {
            return 'not_started';
        }

        // Scenario 2: Task time has started but not ended
        if ($now->gte($taskStartTime) && $now->lt($taskEndTime)) {
            // If tractor is currently in zone, mark as in_progress
            if ($isCurrentlyInZone === true) {
                return 'in_progress';
            }

            // If zone status is unknown, keep current status
            if ($isCurrentlyInZone === null) {
                return $task->status;
            }

            // If tractor is outside zone, mark as stopped
            return 'stopped';
        }

        // Scenario 3: Task time has ended
        return 'done';
    }
}
