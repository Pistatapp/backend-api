<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FilterFarmPlanRequest extends FormRequest
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
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'treatable' => 'nullable|array',
            'treatable.*.treatable_id' => 'required_with:treatable|integer',
            'treatable.*.treatable_type' => 'required_with:treatable|string|in:field,row,tree',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'from_date' => jalali_to_carbon($this->from_date)->format('Y-m-d'),
            'to_date' => jalali_to_carbon($this->to_date)->format('Y-m-d'),
        ]);
    }
}
