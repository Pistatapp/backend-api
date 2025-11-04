<?php

namespace App\Services;

use App\Models\Tractor;
use App\Models\TractorTask;
use Carbon\Carbon;

class TractorTaskService
{
    /**
     * Get the current active task for a tractor at a given timestamp.
     *
     * Priority: in_progress > stopped > not_started
     *
     * @param Carbon $timestamp
     * @param Tractor $tractor
     * @return TractorTask|null
     */
    public function getCurrentTask(Carbon $timestamp, Tractor $tractor): ?TractorTask
    {
        $date = $timestamp->toDateString();

        // Get tasks for current day
        $currentDayTasks = $this->getTasksForDate($tractor, $date, $timestamp);

        // Get tasks for previous day (for midnight crossing)
        $previousDay = $timestamp->copy()->subDay()->toDateString();
        $previousDayTasks = $this->getTasksForDate($tractor, $previousDay, $timestamp);

        $allTasks = $currentDayTasks->concat($previousDayTasks);

        if ($allTasks->isEmpty()) {
            return null;
        }

        // Priority: in_progress > stopped > not_started
        $inProgressTask = $allTasks->where('status', 'in_progress')->first();
        if ($inProgressTask) {
            return $inProgressTask;
        }

        $stoppedTask = $allTasks->where('status', 'stopped')->first();
        if ($stoppedTask) {
            return $stoppedTask;
        }

        $notStartedTask = $allTasks->where('status', 'not_started')->first();
        if ($notStartedTask) {
            return $notStartedTask;
        }

        return null;
    }

    /**
     * Get tasks for a specific date that are active at the given timestamp.
     *
     * @param Tractor $tractor
     * @param string $date
     * @param Carbon $timestamp
     * @return \Illuminate\Support\Collection
     */
    private function getTasksForDate(Tractor $tractor, string $date, Carbon $timestamp): \Illuminate\Support\Collection
    {
        return TractorTask::where('tractor_id', $tractor->id)
            ->whereDate('date', $date)
            ->whereNotIn('status', ['done', 'not_done'])
            ->get()
            ->filter(function ($task) use ($timestamp) {
                return $this->isTaskActiveAtTime($task, $timestamp);
            });
    }

    /**
     * Check if a task is active at the given timestamp.
     *
     * @param TractorTask $task
     * @param Carbon $timestamp
     * @return bool
     */
    private function isTaskActiveAtTime(TractorTask $task, Carbon $timestamp): bool
    {
        $taskDateTime = Carbon::parse($task->date);
        $taskStartDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->start_time);
        $taskEndDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->end_time);

        // Handle midnight crossing
        if ($taskEndDateTime->lt($taskStartDateTime)) {
            $taskEndDateTime->addDay();
        }

        return $timestamp->gte($taskStartDateTime) && $timestamp->lt($taskEndDateTime);
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

        // Get coordinates from taskable (Field, Plot, etc.)
        return $task->taskable->coordinates ?? null;
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
     * Get all active tasks for a tractor on a specific date.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return \Illuminate\Support\Collection
     */
    public function getActiveTasksForDate(Tractor $tractor, Carbon $date): \Illuminate\Support\Collection
    {
        return TractorTask::where('tractor_id', $tractor->id)
            ->whereDate('date', $date)
            ->whereNotIn('status', ['done', 'not_done'])
            ->with(['taskable', 'operation'])
            ->get();
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
            ->orderBy('start_time', 'asc')
            ->get();
    }
}
