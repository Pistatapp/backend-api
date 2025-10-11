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
     * Get the current task for the tractor based on time range.
     *
     * @return TractorTask|null
     */
    public function getCurrentTask(): ?TractorTask
    {
        $currentTask = $this->tractor->tasks()
            ->whereDate('date', now()->format('Y-m-d'))
            ->whereTime('start_time', '<=', now()->format('H:i:s'))
            ->whereTime('end_time', '>=', now()->format('H:i:s'))
            ->first();

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

        return $task->taskable->coordinates;
    }
}
