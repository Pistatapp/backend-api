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
            'labour_id' => 'required|exists:employees,id',
            'pump_id' => 'required|exists:pumps,id',
            'start_time' => [
                'required',
                'date',
                new \App\Rules\ValveTimeOverLap(),
                new \App\Rules\PlotIrrigationTimeOverLap(),
            ],
            'end_time' => [
                'required',
                'date',
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
        $startDate = $this->filled('start_date') ? jalali_to_carbon($this->start_date) : null;
        $endDate = $this->filled('end_date') ? jalali_to_carbon($this->end_date) : $startDate;
        $startTime = $this->filled('start_time') ? Carbon::createFromFormat('H:i', $this->start_time) : null;
        $endTime = $this->filled('end_time') ? Carbon::createFromFormat('H:i', $this->end_time) : null;

        $prepared = [];

        // Combine start_date and start_time into start_time datetime
        if ($startDate && $startTime) {
            $prepared['start_time'] = $startDate->copy()->setTime(
                $startTime->hour,
                $startTime->minute,
                $startTime->second
            );
        } elseif ($startDate) {
            $prepared['start_time'] = $startDate->copy()->startOfDay();
        }

        // Combine end_date and end_time into end_time datetime
        if ($endDate && $endTime) {
            $prepared['end_time'] = $endDate->copy()->setTime(
                $endTime->hour,
                $endTime->minute,
                $endTime->second
            );
        } elseif ($endDate) {
            $prepared['end_time'] = $endDate->copy()->startOfDay();
        }

        if (!empty($prepared)) {
            $this->merge($prepared);
        }
    }
}
