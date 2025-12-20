<?php

namespace App\Services;

use App\Jobs\CalculateTaskGpsMetricsJob;
use App\Events\TractorTaskStatusChanged;
use App\Models\TractorTask;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Service to manage tractor task status based on GPS data and time conditions.
 */
class TractorTaskStatusService
{
    /**
     * Update the status of a tractor task based on current conditions.
     *
     * @param TractorTask $task
     * @param bool|null $isCurrentlyInZone Optional parameter to indicate if tractor is currently in zone
     * @return void
     */
    public function updateTaskStatus(TractorTask $task, ?bool $isCurrentlyInZone = null): void
    {
        $newStatus = $this->determineTaskStatus($task, $isCurrentlyInZone);
        $task->update(['status' => $newStatus]);
        event(new TractorTaskStatusChanged($task, $newStatus, $isCurrentlyInZone));

        CalculateTaskGpsMetricsJob::dispatchIf($newStatus == 'finished', $task);
    }

    /**
     * Determine what the task status should be based on current conditions.
     *
     * Status Logic:
     * - not_started: Task time has not started yet
     * - not_done: Task start time arrived but tractor never entered, OR task ended with less than 30% time in zone
     * - in_progress: Task time started and tractor has entered the area
     * - stopped: Task time has not finished yet, but tractor is working outside task zone
     * - finished: Task ended and tractor was in area for at least 30% of total time
     *
     * @param TractorTask $task
     * @param bool|null $isCurrentlyInZone Optional parameter to indicate if tractor is currently in zone
     * @return string
     */
    private function determineTaskStatus(TractorTask $task, ?bool $isCurrentlyInZone = null): string
    {
        $now = Carbon::now();
        $taskDateTime = Carbon::parse($task->date);
        $taskStartDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->start_time);
        $taskEndDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->end_time);

        // Scenario 1: Task time has not started
        if ($now->lt($taskStartDateTime)) {
            return 'not_started';
        }

        // Scenario 2: Task time has started but not ended
        if ($now->gte($taskStartDateTime) && $now->lt($taskEndDateTime)) {
            // If tractor is currently in zone, mark as in_progress
            if ($isCurrentlyInZone === true) {
                return 'in_progress';
            }

            if ($task->status === 'not_started') {
                return 'not_started';
            }

            return 'stopped';
        }

        return 'finished';
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
     * @param bool|null $isCurrentlyInZone Optional parameter to indicate if tractor is currently in zone
     * @return void
     */
    public function updateTaskStatusAfterGpsProcessing(?TractorTask $task, ?bool $isCurrentlyInZone = null): void
    {
        if (!$task) {
            return;
        }

        $this->updateTaskStatus($task, $isCurrentlyInZone);
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

        // Update if task is currently not_started or stopped and we're within task time
        if (
            in_array($task->status, ['not_started', 'stopped']) &&
            $now->gte($taskStartDateTime) &&
            $now->lt($taskEndDateTime)
        ) {

            $task->update(['status' => 'in_progress']);
            event(new TractorTaskStatusChanged($task, 'in_progress', true));

            Log::info('Tractor task marked as in_progress', [
                'task_id' => $task->id,
                'tractor_id' => $task->tractor_id,
                'previous_status' => $task->status,
            ]);
        }
    }

    /**
     * Finalize any ended tasks that are still in_progress, stopped, or not_started status.
     * This is useful when GPS reports come in after task end time.
     *
     * @param int $tractorId
     * @param string $date
     * @return void
     */
    public function finalizeEndedTasks(int $tractorId, string $date): void
    {
        $now = Carbon::now();

        $tasks = TractorTask::where('tractor_id', $tractorId)
            ->whereDate('date', $date)
            ->whereIn('status', ['not_started', 'in_progress', 'stopped'])
            ->get();

        foreach ($tasks as $task) {
            $taskDateTime = Carbon::parse($task->date);
            $taskEndDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->end_time);
            $taskStartDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->start_time);

            if ($taskEndDateTime->lt($taskStartDateTime)) {
                $taskEndDateTime->addDay();
            }

            // Only finalize if task has actually ended
            if ($now->gte($taskEndDateTime)) {
                $this->updateTaskStatus($task);
            }
        }
    }

    /**
     * Mark task as stopped when tractor exits the task zone during task time.
     * Called when GPS processing detects tractor is outside task zone.
     *
     * @param TractorTask $task
     * @return void
     */
    public function markTaskStoppedIfApplicable(TractorTask $task): void
    {
        $now = Carbon::now();
        $taskDateTime = Carbon::parse($task->date);
        $taskStartDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->start_time);
        $taskEndDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->end_time);

        if ($taskEndDateTime->lt($taskStartDateTime)) {
            $taskEndDateTime->addDay();
        }

        // Only update if task is currently in_progress and we're within task time
        if (
            $task->status === 'in_progress' &&
            $now->gte($taskStartDateTime) &&
            $now->lt($taskEndDateTime)
        ) {

            $task->update(['status' => 'stopped']);
            event(new TractorTaskStatusChanged($task, 'stopped', false));

            Log::info('Tractor task marked as stopped', [
                'task_id' => $task->id,
                'tractor_id' => $task->tractor_id,
            ]);
        }
    }
}
