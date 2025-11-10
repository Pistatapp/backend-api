<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class StoreIrrigationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Irrigation::class);
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
            'date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:date',
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
        $startDateInput = $this->input('start_date', $this->input('date'));
        $startDate = $startDateInput ? jalali_to_carbon($startDateInput) : null;
        $endDate = $this->filled('end_date') ? jalali_to_carbon($this->end_date) : null;
        $startTime = $this->filled('start_time') ? Carbon::createFromFormat('H:i', $this->start_time) : null;
        $endTime = $this->filled('end_time') ? Carbon::createFromFormat('H:i', $this->end_time) : null;

        $prepared = [];

        if ($startDate) {
            $prepared['date'] = $startDate;
            $prepared['start_date'] = $startDate;
        }

        if ($endDate) {
            $prepared['end_date'] = $endDate;
        }

        if ($startTime) {
            $prepared['start_time'] = $startTime;
        }

        if ($endTime) {
            $prepared['end_time'] = $endTime;
        }

        if (!empty($prepared)) {
            $this->merge($prepared);
        }
    }
}
