<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\TractorTask;
use App\Rules\UniqueTractorTask;

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
            'taskable_type' => 'required|string|in:App\Models\Field,App\Models\Farm,App\Models\Plot,App\Models\Row',
            'taskable_id' => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    $taskableType = $this->input('taskable_type');
                    if (!class_exists($taskableType)) {
                        $fail('The selected taskable type is invalid.');
                        return;
                    }

                    $model = $taskableType::find($value);
                    if (!$model) {
                        $fail('The selected taskable does not exist.');
                        return;
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
                new UniqueTractorTask,
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
        $taskableTypeMap = [
            'field' => 'App\Models\Field',
            'farm'  => 'App\Models\Farm',
            'plot'  => 'App\Models\Plot',
            'row'   => 'App\Models\Row',
        ];

        $taskableType = $this->input('taskable_type');
        $modelClass = $taskableTypeMap[$taskableType] ?? $taskableType;

        $this->merge([
            'date' => jalali_to_carbon($this->date),
            'taskable_type' => $modelClass,
        ]);
    }
}
