<?php

namespace App\Http\Requests;

use App\Models\TractorTask;
use App\Rules\UniqueTractorTask;
use Illuminate\Foundation\Http\FormRequest;

class StoreTractorTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', TractorTask::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'operation_id' => 'required|integer|exists:operations,id',
            'taskable_type' => 'required|string|in:row,field,farm,plot',
            'taskable_ids' => ['required', 'array', 'min:1'],
            'taskable_ids.*' => [
                'integer',
                'distinct',
                function ($attribute, $value, $fail) {
                    $slug = $this->input('taskable_type');
                    if (! $slug || ! in_array($slug, ['row', 'field', 'farm', 'plot'], true)) {
                        $fail(__('The selected taskable type is invalid.'));

                        return;
                    }

                    try {
                        $modelClass = getModelClass($slug);
                    } catch (\InvalidArgumentException) {
                        $fail(__('The selected taskable type is invalid.'));

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
                    if (strtotime($value) <= strtotime($this->start_time)) {
                        $fail('The end time must be after the start time.');
                    }
                },
            ],
            'description' => 'nullable|string|max:255',
        ];
    }

    /**
     * Prepare the data for validation.
     */
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
