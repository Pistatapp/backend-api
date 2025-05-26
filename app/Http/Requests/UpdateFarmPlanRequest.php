<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFarmPlanRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'goal' => 'required|string|max:2000',
            'referrer' => 'required|string|max:255',
            'counselors' => 'required|string|max:255',
            'executors' => 'required|string|max:255',
            'statistical_counselors' => 'required|string|max:255',
            'implementation_location' => 'required|string|max:255',
            'used_materials' => 'required|string|max:255',
            'evaluation_criteria' => 'required|string|max:255',
            'description' => 'required|string|max:2000',
            'start_date' => [
                'required',
                'date',
                function ($attribute, $value, $fail) {
                    if ($this->end_date && $value > $this->end_date) {
                        $fail('The start date must be before the end date.');
                    }
                },
            ],
            'end_date' => [
                'required',
                'date',
                'after_or_equal:start_date',
                function ($attribute, $value, $fail) {
                    if ($this->start_date && $value < $this->start_date) {
                        $fail('The end date must be after or equal to the start date.');
                    }
                },
            ],
            'details' => 'required|array|min:1',
            'details.*.treatment_id' => 'required|exists:treatments,id',
            'details.*.treatables' => 'required|array|min:1',
            'details.*.treatables.*.treatable_id' => 'required|integer',
            'details.*.treatables.*.treatable_type' => 'required|string|in:field,row,tree',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'start_date' => jalali_to_carbon($this->start_date),
            'end_date' => jalali_to_carbon($this->end_date),
        ]);
    }
}
