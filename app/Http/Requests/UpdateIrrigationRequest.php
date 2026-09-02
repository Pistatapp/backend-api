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
            'labour_id' => 'sometimes|required|exists:labours,id',
            'pump_id' => 'sometimes|required|exists:pumps,id',
            'start_time' => [
                'sometimes',
                'date',
                new \App\Rules\ValveTimeOverLap(),
                new \App\Rules\PlotIrrigationTimeOverLap(),
            ],
            'end_time' => [
                'sometimes',
                'date',
                'after:start_time',
                new \App\Rules\ValveTimeOverLap(),
                new \App\Rules\PlotIrrigationTimeOverLap(),
            ],
            'plots' => 'sometimes|required|array',
            'plots.*' => 'integer|exists:plots,id',
            'valves' => 'sometimes|required|array',
            'valves.*' => 'integer|exists:valves,id',
            'note' => 'sometimes|nullable|string|max:500',
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $irrigation = $this->route('irrigation');
        $existingStart = $irrigation?->start_time;
        $existingEnd = $irrigation?->end_time;
        $hasTemporalInput = $this->hasAny(['start_date', 'end_date', 'start_time', 'end_time']);

        if (! $hasTemporalInput) {
            return;
        }

        // Resolve omitted date/time components from the persisted record. This
        // makes a partial edit such as {end_time: "12:00"} a real partial edit
        // instead of turning the other half of the interval into null/midnight.
        $startDate = $this->filled('start_date')
            ? jalali_to_carbon($this->start_date)
            : $existingStart?->copy();
        $endDate = $this->filled('end_date')
            ? jalali_to_carbon($this->end_date)
            : ($this->has('start_date') && $this->filled('start_date')
                ? $startDate?->copy()
                : $existingEnd?->copy());
        $startTimeWasSent = $this->has('start_time');
        $endTimeWasSent = $this->has('end_time');
        $startTime = $this->filled('start_time')
            ? $this->parseTime($this->start_time)
            : $existingStart;
        $endTime = $this->filled('end_time')
            ? $this->parseTime($this->end_time)
            : $existingEnd;

        $prepared = [];

        // Combine start_date and start_time into start_time datetime
        if ($startDate && $startTime) {
            $prepared['start_time'] = $startDate->copy()->setTime(
                $startTime->hour,
                $startTime->minute,
                $startTime->second
            );
        } elseif ($startDate && ! $startTimeWasSent) {
            $prepared['start_time'] = $startDate->copy()->startOfDay();
        }

        // Combine end_date and end_time into end_time datetime
        if ($endDate && $endTime) {
            $prepared['end_time'] = $endDate->copy()->setTime(
                $endTime->hour,
                $endTime->minute,
                $endTime->second
            );
        } elseif ($endDate && ! $endTimeWasSent) {
            $prepared['end_time'] = $endDate->copy()->startOfDay();
        }

        if (!empty($prepared)) {
            $this->merge($prepared);
        }
    }

    private function parseTime(mixed $value): ?Carbon
    {
        try {
            return Carbon::createFromFormat('H:i', (string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
