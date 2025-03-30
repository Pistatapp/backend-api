<?php

namespace App\Services;

use App\Models\Tractor;
use App\Models\TractorTask;
use Illuminate\Support\Collection;

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
        \Illuminate\Support\Facades\Log::info('Getting task area', [
            'task_id' => $task->id,
            'field_id' => $task->field_id,
            'coordinates' => $task->field->coordinates
        ]);
        return $task->fetchTaskArea();
    }
}
