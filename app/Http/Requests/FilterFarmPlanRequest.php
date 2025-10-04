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
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
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
        $data = [];

        if ($this->has('from_date')) {
            $data['from_date'] = jalali_to_carbon($this->from_date);
        }

        if ($this->has('to_date')) {
            $data['to_date'] = jalali_to_carbon($this->to_date);
        }

        $this->merge($data);
    }
}
