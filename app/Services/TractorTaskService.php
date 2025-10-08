<?php

namespace App\Services;

use App\Models\Tractor;
use App\Models\TractorTask;

class TractorTaskService
{
    public function __construct(
        private Tractor $tractor
    ) {}

    /**
     * Get the current task for the tractor based on time range and GPS report time.
     *
     * This method intelligently determines which task is currently active by:
     * 1. Finding tasks scheduled for today
     * 2. Checking if current time falls within any task's time range
     * 3. Prioritizing tasks that are already in_progress
     * 4. Excluding tasks that are already done or marked as not_done
     *
     * @param \Carbon\Carbon|null $reportTime The time of the GPS report (defaults to now)
     * @return TractorTask|null
     */
    public function getCurrentTask(?\Carbon\Carbon $reportTime = null): ?TractorTask
    {
        $reportTime = $reportTime ?? now();
        $today = $reportTime->toDateString();
        $yesterday = $reportTime->copy()->subDay()->toDateString();

        // Get all tasks for today and yesterday (for midnight crossing tasks) that are not finished
        $tasks = $this->tractor->tasks()
            ->where(function($query) use ($today, $yesterday) {
                $query->whereDate('date', $today)
                      ->orWhereDate('date', $yesterday);
            })
            ->whereNotIn('status', ['done', 'not_done'])
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        if ($tasks->isEmpty()) {
            return null;
        }

        $currentTask = null;

        foreach ($tasks as $task) {
            // Extract only the time portion (HH:MM) from start_time and end_time
            $startTime = is_string($task->start_time) ? $task->start_time : $task->start_time->format('H:i');
            $endTime = is_string($task->end_time) ? $task->end_time : $task->end_time->format('H:i');

            // Get the task's date (might be today or yesterday)
            $taskDate = $task->date instanceof \Carbon\Carbon ? $task->date->toDateString() : $task->date;

            $taskStart = \Carbon\Carbon::parse($taskDate . ' ' . $startTime);
            $taskEnd = \Carbon\Carbon::parse($taskDate . ' ' . $endTime);

            // Handle tasks that cross midnight
            if ($taskEnd->lt($taskStart)) {
                $taskEnd->addDay();
            }

            // Check if report time falls within this task's time range
            if ($reportTime->gte($taskStart) && $reportTime->lte($taskEnd)) {
                // Prioritize tasks that are already in_progress
                if ($task->status === 'in_progress') {
                    return $task;
                }

                // Otherwise, save as potential current task
                if (!$currentTask) {
                    $currentTask = $task;
                }
            }
        }

        return $currentTask;
    }

    /**
     * Get the zone of the specified task.
     *
     * @param TractorTask|null $task
     * @return array|null
     */
    public function getTaskZone(?TractorTask $task): ?array
    {
        if (!$task) {
            return null;
        }

        $task->loadMissing('taskable');

        // Check if the taskable model exists and has coordinates
        if ($task->taskable && isset($task->taskable->coordinates)) {
            return $this->normalizeCoordinates($task->taskable->coordinates);
        }

        // For backward compatibility, try to load a relationship named after the taskable type (lowercase)
        $modelName = class_basename($task->taskable_type); // e.g., 'Field', 'Plot', etc.
        $relation = strtolower($modelName);
        if ($task->relationLoaded($relation) || method_exists($task, $relation)) {
            $task->loadMissing("{$relation}:id,coordinates");
            $coords = $task->{$relation}->coordinates ?? null;
            return $coords ? $this->normalizeCoordinates($coords) : null;
        }

        return null;
    }

    /**
     * Normalize polygon coordinates to [[lat, lon], ...] as floats.
     * Accepts arrays of strings like "lat,lon" or nested arrays of strings/numbers.
     */
    private function normalizeCoordinates(array $coordinates): array
    {
        $normalized = [];

        foreach ($coordinates as $point) {
            if (is_string($point)) {
                // Format: "lat,lon"
                [$lat, $lon] = array_map('trim', explode(',', $point));
                $normalized[] = [ (float)$lat, (float)$lon ];
                continue;
            }

            if (is_array($point)) {
                // Format: [lat, lon] possibly as strings
                if (count($point) >= 2) {
                    $lat = is_string($point[0]) ? (float)trim($point[0]) : (float)$point[0];
                    $lon = is_string($point[1]) ? (float)trim($point[1]) : (float)$point[1];
                    $normalized[] = [ $lat, $lon ];
                }
                continue;
            }
        }

        return $normalized;
    }
}
