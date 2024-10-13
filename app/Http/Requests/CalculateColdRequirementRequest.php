<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalculateColdRequirementRequest extends FormRequest
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
            'method' => 'required|string|in:method1,method2',
            'start_dt' => 'required|date',
            'end_dt' => 'required|date',
            'crop_type_id' => 'required|exists:crop_types,id',
            'min_temp' => 'nullable|integer|required_with:max_temp',
            'max_temp' => 'nullable|integer|gte:min_temp|required_with:min_temp',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'start_dt' => jalali_to_carbon($this->start_dt)->format('Y-m-d'),
            'end_dt' => jalali_to_carbon($this->end_dt)->format('Y-m-d'),
        ]);
    }
}
