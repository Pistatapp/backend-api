<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Models\TrucktorTask;

class UniqueTrucktorTask implements ValidationRule, DataAwareRule
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
        $trucktor = request()->route('trucktor');
        $task = request()->route('trucktor_task');
        $startDate = $this->data['start_date'];
        $endDate = $this->data['end_date'];

        if($trucktor === null) {
            $trucktor = $task->trucktor;
        }

        $existingTask = TrucktorTask::whereBelongsTo($trucktor)
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    ->orWhere(function ($query) use ($startDate, $endDate) {
                        $query->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                    });
            });

        if ($task !== null) {
            $existingTask->where('id', '!=', $task->id);
        }

        $existingTask = $existingTask->exists();

        if ($existingTask) {
            $fail(__('A task already exists for the vehicle within the selected date range.'));
        }
    }
}
