<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\TractorTask;

class FilterTractorTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', TractorTask::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'fields' => 'nullable|array',
            'fields.*' => 'integer|exists:fields,id',
            'operations' => 'nullable|array',
            'operations.*' => 'integer|exists:operations,id',
            'tractor_id' => 'required|exists:tractors,id',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_date.required' => 'The start date is required.',
            'start_date.date' => 'The start date must be a valid Jalali date.',
            'end_date.required' => 'The end date is required.',
            'end_date.date' => 'The end date must be a valid Jalali date.',
            'end_date.after_or_equal' => 'The end date must be after or equal to the start date.',
            'fields.array' => 'The fields must be an array.',
            'fields.*.integer' => 'Each field ID must be an integer.',
            'fields.*.exists' => 'The selected field does not exist.',
            'operations.array' => 'The operations must be an array.',
            'operations.*.integer' => 'Each operation ID must be an integer.',
            'operations.*.exists' => 'The selected operation does not exist.',
            'tractor_id.required' => 'The tractor ID is required.',
            'tractor_id.exists' => 'The selected tractor does not exist.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Convert Jalali dates to Carbon objects
        $this->merge([
            'start_date' => jalali_to_carbon($this->start_date),
            'end_date' => jalali_to_carbon($this->end_date),
        ]);
    }
}
