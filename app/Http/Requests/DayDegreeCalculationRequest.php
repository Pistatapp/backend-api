<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DayDegreeCalculationRequest extends FormRequest
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
            'start_dt' => 'required|date',
            'end_dt' => 'required|date',
            'model_type' => 'required|string|in:crop_type,pest',
            'model_id' => 'required|integer',
            'min_temp' => 'nullable|numeric|required_with:max_temp',
            'max_temp' => 'nullable|numeric|gte:min_temp|required_with:min_temp',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'start_dt' => $this->start_dt ? jalali_to_carbon($this->start_dt) : null,
            'end_dt' => $this->end_dt ? jalali_to_carbon($this->end_dt) : null,
        ]);
    }
}
