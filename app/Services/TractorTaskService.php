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
        return $this->tractor->tasks()
            ->started()
            ->forDate(today())
            ->first();
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

        $task->loadMissing('field:id,coordinates');

        return $task->field->coordinates;
    }
}
