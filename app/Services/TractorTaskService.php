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
                ->with('taskable')
                ->first();
        });

        return $currentTask;
    }

    /**
     * Get the zone coordinates for a task.
     *
     * @param TractorTask $task
     * @return array|null
     */
    public function getTaskZone(TractorTask $task): ?array
    {
        if (!$task->taskable) {
            return null;
        }

        $task->loadMissing('taskable');

        // Get coordinates from taskable (Field, Plot, etc.)
        return $task->taskable->coordinates;
    }

    /**
     * Check if a GPS point is within the task zone.
     *
     * @param array $point [latitude, longitude]
     * @param TractorTask $task
     * @return bool
     */
    public function isPointInTaskZone(array $point, TractorTask $task): bool
    {
        $zone = $this->getTaskZone($task);

        if (!$zone) {
            return false;
        }

        return is_point_in_polygon($point, $zone);
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
            ->with(['taskable', 'operation'])
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
        $task->update(['status' => $newStatus]);
        event(new TractorTaskStatusChanged($task, $newStatus, $isCurrentlyInZone));

        CalculateTaskGpsMetricsJob::dispatchIf($newStatus == 'done', $task);
    }

    /**
     * Determine what the task status should be based on current conditions.
     *
     * Status Logic:
     * - not_started: Task time has not started yet
     * - not_done: Task start time arrived but tractor never entered, OR task ended with less than 30% time in zone
     * - in_progress: Task time started and tractor has entered the area
     * - stopped: Task time has not finished yet, but tractor is working outside task zone
     * - done: Task ended (regardless of zone status)
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

        $taskDate = Carbon::parse($task->date);
        $taskStartTime = $taskDate->copy()->setTimeFromTimeString($task->start_time);
        $taskEndTime = $taskDate->copy()->setTimeFromTimeString($task->end_time);

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
