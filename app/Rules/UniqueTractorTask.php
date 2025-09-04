<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Models\TractorTask;

class UniqueTractorTask implements ValidationRule, DataAwareRule
{
    /**
     * The data the validation rule has access to.
     *
     * @var array<string, mixed>
     */
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
                // Simplified overlap logic: two time ranges overlap if and only if
                // one starts before the other ends AND the other starts before the first ends
                $query->where('start_time', '<', $endTime)
                      ->where('end_time', '>', $startTime);
            });

        if ($task = request()->route('tractor_task')) {
            $existingTaskQuery->where('id', '!=', $task->id);
        }

        if ($existingTaskQuery->exists()) {
            $fail(__('A task already exists for the tractor within the selected time range on this date.'));
        }
    }
}
