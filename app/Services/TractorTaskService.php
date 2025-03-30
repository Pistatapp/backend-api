<?php

namespace App\Services;

use App\Models\Tractor;
use App\Models\TractorTask;

class TractorTaskService
{
    public function __construct(
        private Tractor $tractor
    ) {}

    public function getCurrentTask(): ?TractorTask
    {
        return $this->tractor->tasks()
            ->with('field:id,coordinates')
            ->started()
            ->forDate(today())
            ->first();
    }

    public function getTaskArea(?TractorTask $task): ?array
    {
        if (!$task) {
            return null;
        }

        $task->load('field:id,coordinates');

        return $task->fetchTaskArea();
    }
}
