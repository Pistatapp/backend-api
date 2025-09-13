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
     * Get the current task for the tractor.
     *
     * @return TractorTask|null
     */
    public function getCurrentTask(): ?TractorTask
    {
        $task = $this->tractor->tasks()
            ->started()
            ->forDate(today()->toDateString())
            ->first();

        return $task;
    }

    /**
     * Get the area of the specified task.
     *
     * @param TractorTask|null $task
     * @return array|null
     */
    public function getTaskArea(?TractorTask $task): ?array
    {
        if (!$task) {
            return null;
        }

        $task->loadMissing('taskable');

        // Check if the taskable model exists and has coordinates
        if ($task->taskable && isset($task->taskable->coordinates)) {
            return $task->taskable->coordinates;
        }

        // For backward compatibility, try to load a relationship named after the taskable type (lowercase)
        $modelName = class_basename($task->taskable_type); // e.g., 'Field', 'Plot', etc.
        $relation = strtolower($modelName);
        if ($task->relationLoaded($relation) || method_exists($task, $relation)) {
            $task->loadMissing("{$relation}:id,coordinates");
            return $task->{$relation}->coordinates ?? null;
        }

        return null;
    }
}
