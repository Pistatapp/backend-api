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
        return $this->user()->can('update', $this->route('irrigation'));
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
        $startDate = jalali_to_carbon($this->start_date);
        $endDate = jalali_to_carbon($this->end_date);
        $startTime = Carbon::createFromFormat('H:i', $this->start_time);
        $endTime = Carbon::createFromFormat('H:i', $this->end_time);

        // Combine start_date and start_time into start_time datetime
        $combinedStartTime = $startDate->copy()->setTime(
            $startTime->hour,
            $startTime->minute,
            $startTime->second
        );

        // Combine end_date and end_time into end_time datetime
        $combinedEndTime = $endDate->copy()->setTime(
            $endTime->hour,
            $endTime->minute,
            $endTime->second
        );

        $this->merge([
            'start_time' => $combinedStartTime,
            'end_time' => $combinedEndTime,
        ]);
    }
}
