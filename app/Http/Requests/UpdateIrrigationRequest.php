<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIrrigationRequest extends FormRequest
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
            'labour_id' => 'required|exists:labours,id',
            'date' => 'required|shamsi_date',
            'start_time' => [
                'required',
                'date_format:H:i',
                new \App\Rules\ValveTimeOverLap(),
            ],
            'end_time' => [
                'required',
                'date_format:H:i',
                'after:start_time',
                new \App\Rules\ValveTimeOverLap(),
            ],
            'valves' => 'required|array',
            'valves.*' => 'exists:valves,id',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'date' => jalali_to_carbon($this->date)->format('Y-m-d'),
        ]);
    }
}
