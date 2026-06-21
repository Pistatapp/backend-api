<?php

namespace App\Http\Requests;

use App\Rules\UniqueTractorTask;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

abstract class TractorTaskBaseRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    protected function sharedTaskRules(): array
    {
        return [
            'operation_id' => 'required|integer|exists:operations,id',
            'taskable_type' => 'required|string|in:row,field,farm,plot',
            'taskable_ids' => ['required', 'array', 'min:1'],
            'taskable_ids.*' => [
                'integer',
                'distinct',
                function ($attribute, $value, $fail) {
                    $modelClass = $this->resolveTaskableModelClass($fail);

                    if ($modelClass === null) {
                        return;
                    }

                    if (! $modelClass::find($value)) {
                        $fail(__('The selected taskable does not exist.'));
                    }
                },
            ],
            'date' => 'required|date',
            'start_time' => [
                'required',
                'date_format:H:i',
                new UniqueTractorTask,
            ],
            'end_time' => [
                'required',
                'date_format:H:i',
                function ($attribute, $value, $fail) {
                    $startTime = $this->input('start_time');

                    if (! $startTime || Carbon::parse($value)->lte(Carbon::parse($startTime))) {
                        $fail('The end time must be after the start time.');
                    }
                },
            ],
        ];
    }

    /**
     * @param  \Closure(string): void  $fail
     */
    protected function resolveTaskableModelClass(\Closure $fail): ?string
    {
        $slug = $this->input('taskable_type');

        if (! $slug || ! in_array($slug, ['row', 'field', 'farm', 'plot'], true)) {
            $fail(__('The selected taskable type is invalid.'));

            return null;
        }

        try {
            return getModelClass($slug);
        } catch (\InvalidArgumentException) {
            $fail(__('The selected taskable type is invalid.'));

            return null;
        }
    }

    protected function prepareForValidation(): void
    {
        $merge = [
            'date' => jalali_to_carbon($this->date),
        ];

        if (! $this->filled('taskable_ids') && $this->filled('taskable_id')) {
            $merge['taskable_ids'] = [(int) $this->input('taskable_id')];
        }

        $this->merge($merge);
    }
}
