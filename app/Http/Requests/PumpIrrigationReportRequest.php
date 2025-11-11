<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PumpIrrigationReportRequest extends FormRequest
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
            'start_date' => 'required|date',
            'end_date' => 'required|date',
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Check if dates were successfully converted to Carbon instances
            if ($this->start_date instanceof \Carbon\Carbon && $this->end_date instanceof \Carbon\Carbon) {
                if ($this->end_date->lt($this->start_date)) {
                    $validator->errors()->add('end_date', 'The end date must be after or equal to the start date.');
                }
            }
        });
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        // Store original values for validation
        $originalStartDate = $this->start_date;
        $originalEndDate = $this->end_date;

        // Convert shamsi dates to Carbon instances
        if ($this->start_date && is_jalali_date($this->start_date)) {
            try {
                $this->merge([
                    'start_date' => jalali_to_carbon($this->start_date),
                ]);
            } catch (\Exception $e) {
                // Keep original value if conversion fails - validation will catch it
                $this->merge(['start_date' => $originalStartDate]);
            }
        }

        if ($this->end_date && is_jalali_date($this->end_date)) {
            try {
                $this->merge([
                    'end_date' => jalali_to_carbon($this->end_date),
                ]);
            } catch (\Exception $e) {
                // Keep original value if conversion fails - validation will catch it
                $this->merge(['end_date' => $originalEndDate]);
            }
        }
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
            'end_date.required' => 'The end date is required.',
        ];
    }
}

