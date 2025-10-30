<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Carbon\Carbon;

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
            'pump_id' => 'required|exists:pumps,id',
            'start_date' => 'required|shamsi_date',
            'end_date' => 'nullable|shamsi_date|after_or_equal:start_date',
            'start_time' => [
                'required',
                new \App\Rules\ValveTimeOverLap(),
                new \App\Rules\PlotIrrigationTimeOverLap(),
            ],
            'end_time' => [
                'required',
                'after:start_time',
                new \App\Rules\ValveTimeOverLap(),
                new \App\Rules\PlotIrrigationTimeOverLap(),
            ],
            'plots' => 'required|array',
            'plots.*' => 'required|integer|exists:plots,id',
            'valves' => 'required|array',
            'valves.*' => 'required|integer|exists:valves,id',
            'note' => 'nullable|string|max:500',
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
            'start_date' => jalali_to_carbon($this->start_date)->format('Y/m/d'),
            'end_date' => $this->end_date ? jalali_to_carbon($this->end_date)->format('Y/m/d') : null,
            'start_time' => Carbon::createFromFormat('H:i', $this->start_time),
            'end_time' => Carbon::createFromFormat('H:i', $this->end_time),
        ]);
    }
}
