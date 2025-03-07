<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Models\TractorTask;

class UniqueTractorTask implements ValidationRule, DataAwareRule
{
    protected $data = [];

    /**
     * Set the data the validation rule has access to.
     *
     * @param  array<string, mixed>  $data
     * @return static
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $tractor = request()->route('tractor') ?? request()->route('tractor_task')->tractor;
        $date = $this->data['date'];
        $startTime = $this->data['start_time'];
        $endTime = $this->data['end_time'];

        $existingTaskQuery = TractorTask::whereBelongsTo($tractor)
            ->where('date', $date)
            ->where(function ($query) use ($startTime, $endTime) {
                $query->whereBetween('start_time', [$startTime, $endTime])
                    ->orWhereBetween('end_time', [$startTime, $endTime])
                    ->orWhere(function ($query) use ($startTime, $endTime) {
                        $query->where('start_time', '<=', $startTime)
                            ->where('end_time', '>=', $endTime);
                    });
            });

        if ($task = request()->route('tractor_task')) {
            $existingTaskQuery->where('id', '!=', $task->id);
        }

        if ($existingTaskQuery->exists()) {
            $fail(__('A task already exists for the vehicle within the selected time range.'));
        }

        // Ensure only a single task per day
        $dailyTaskQuery = TractorTask::whereBelongsTo($tractor)
            ->where('date', $date);

        if ($task = request()->route('tractor_task')) {
            $dailyTaskQuery->where('id', '!=', $task->id);
        }

        if ($dailyTaskQuery->exists()) {
            $fail(__('A task already exists for the vehicle on the selected date.'));
        }
    }
}
