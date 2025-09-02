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
