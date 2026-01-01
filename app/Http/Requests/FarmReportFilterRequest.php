<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Morilog\Jalali\Jalalian;

class FarmReportFilterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'filters' => ['required', 'array'],
            'filters.reportable_type' => ['sometimes', 'string'],
            'filters.reportable_id' => ['sometimes', 'array'],
            'filters.reportable_id.*' => ['required', 'integer'],
            'filters.operation_ids' => ['sometimes', 'array'],
            'filters.operation_ids.*' => ['required', 'integer', 'exists:operations,id'],
            'filters.labour_ids' => ['sometimes', 'array'],
            'filters.labour_ids.*' => ['required', 'integer', 'exists:labours,id'],
            'filters.date_range' => ['sometimes', 'array'],
            'filters.date_range.from' => ['required_with:filters.date_range', 'date'],
            'filters.date_range.to' => ['required_with:filters.date_range', 'date', 'after_or_equal:filters.date_range.from'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        if (isset($this->filters['date_range'])) {
            $this->merge([
                'filters' => array_merge($this->filters, [
                    'date_range' => [
                        'from' => jalali_to_carbon($this->input('filters.date_range.from')),
                        'to' => jalali_to_carbon($this->input('filters.date_range.to')),
                    ]
                ])
            ]);
        }
    }
}
