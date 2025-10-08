<?php

namespace App\Services;

use App\Events\TractorTaskStatusChanged;
use App\Models\TractorTask;
use App\Models\GpsMetricsCalculation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Service to manage tractor task status based on GPS data and time conditions.
 */
class TractorTaskStatusService
{
    /**
     * Minimum percentage of time tractor must be in zone to mark task as done.
     */
    private const MINIMUM_PRESENCE_PERCENTAGE = 30;

    /**
     * Update the status of a tractor task based on current conditions.
     *
     * @param TractorTask $task
     * @return void
     */
    public function updateTaskStatus(TractorTask $task): void
    {
        $oldStatus = $task->status;
        $newStatus = $this->determineTaskStatus($task);

        if ($oldStatus !== $newStatus) {
            $task->update(['status' => $newStatus]);
            event(new TractorTaskStatusChanged($task, $newStatus));
        }
    }

    /**
     * Determine what the task status should be based on current conditions.
     *
     * Status Logic:
     * - not_started: Task time has not started yet
     * - not_done: Task start time arrived but tractor never entered, OR task ended with less than 30% time in zone
     * - in_progress: Task time started and tractor has entered the area
     * - done: Task ended and tractor was in area for at least 30% of total time
     *
     * @param TractorTask $task
     * @return string
     */
    private function determineTaskStatus(TractorTask $task): string
    {
        $now = Carbon::now();
        $taskDateTime = Carbon::parse($task->date);
        $taskStartDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->start_time);
        $taskEndDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->end_time);

        // Handle case where end time is before start time (crosses midnight)
        if ($taskEndDateTime->lt($taskStartDateTime)) {
            $taskEndDateTime->addDay();
        }

        // Scenario 1: Task time has not started
        if ($now->lt($taskStartDateTime)) {
            return 'not_started';
        }

        // Get GPS metrics for this task
        $gpsMetrics = $this->getTaskGpsMetrics($task);
        $hasEnteredZone = $gpsMetrics && $gpsMetrics->work_duration > 0;

        // Scenario 2: Task time has started but not ended
        if ($now->gte($taskStartDateTime) && $now->lt($taskEndDateTime)) {
            // If tractor has entered the zone, it's in progress
            if ($hasEnteredZone) {
                return 'in_progress';
            }
            // If task started but tractor hasn't entered yet, check if it's still not_started or should be not_done
            // We'll keep it as not_started until the task ends
            return $task->status === 'not_started' ? 'not_started' : $task->status;
        }

        // Scenario 3 & 4: Task time has ended
        if ($now->gte($taskEndDateTime)) {
            // If tractor never entered the zone
            if (!$hasEnteredZone) {
                return 'not_done';
            }

            // Calculate percentage of time spent in zone
            $totalTaskDuration = $taskStartDateTime->diffInSeconds($taskEndDateTime);
            $timeInZone = $gpsMetrics->work_duration; // in seconds

            $presencePercentage = ($timeInZone / $totalTaskDuration) * 100;

            // If tractor was in zone for at least 30% of the time, task is done
            if ($presencePercentage >= self::MINIMUM_PRESENCE_PERCENTAGE) {
                return 'done';
            } else {
                return 'not_done';
            }
        }

        // Default: return current status
        return $task->status;
    }

    /**
     * Get GPS metrics calculation for a specific task.
     *
     * @param TractorTask $task
     * @return GpsMetricsCalculation|null
     */
    private function getTaskGpsMetrics(TractorTask $task): ?GpsMetricsCalculation
    {
        $date = $task->date instanceof \Carbon\Carbon
            ? $task->date->toDateString()
            : $task->date;

        return GpsMetricsCalculation::where('tractor_id', $task->tractor_id)
            ->where('tractor_task_id', $task->id)
            ->where('date', $date)
            ->first();
    }

    /**
     * Update status for all tasks of a specific tractor on a given date.
     *
     * @param int $tractorId
     * @param string $date
     * @return void
     */
    public function updateTasksForTractor(int $tractorId, string $date): void
    {
        $tasks = TractorTask::where('tractor_id', $tractorId)
            ->whereDate('date', $date)
            ->get();

        foreach ($tasks as $task) {
            $this->updateTaskStatus($task);
        }
    }

    /**
     * Update status for a specific task when GPS metrics are updated.
     * This is called from ProcessGpsReportsJob after metrics are calculated.
     *
     * @param TractorTask|null $task
     * @return void
     */
    public function updateTaskStatusAfterGpsProcessing(?TractorTask $task): void
    {
        if (!$task) {
            return;
        }

        $this->updateTaskStatus($task);
    }

    /**
     * Check if task should transition to 'in_progress' when tractor enters zone.
     * Called when GPS processing detects tractor is in task zone.
     *
     * @param TractorTask $task
     * @return void
     */
    public function markTaskInProgressIfApplicable(TractorTask $task): void
    {
        $now = Carbon::now();
        $taskDateTime = Carbon::parse($task->date);
        $taskStartDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->start_time);
        $taskEndDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->end_time);

        if ($taskEndDateTime->lt($taskStartDateTime)) {
            $taskEndDateTime->addDay();
        }

        // Only update if task is currently not_started and we're within task time
        if ($task->status === 'not_started' &&
            $now->gte($taskStartDateTime) &&
            $now->lt($taskEndDateTime)) {

            $task->update(['status' => 'in_progress']);
            event(new TractorTaskStatusChanged($task, 'in_progress'));

            Log::info('Tractor task marked as in_progress', [
                'task_id' => $task->id,
                'tractor_id' => $task->tractor_id,
            ]);
        }
    }
}

