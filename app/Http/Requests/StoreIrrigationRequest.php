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
                new \App\Rules\ValveTimeOverLap(),
                new \App\Rules\FieldIrrigationTimeOverLap(),
            ],
            'end_time' => [
                'required',
                'after:start_time',
                new \App\Rules\ValveTimeOverLap(),
                new \App\Rules\FieldIrrigationTimeOverLap(),
            ],
            'fields' => 'required|array',
            'fields.*' => 'required|integer|exists:fields,id',
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
            'date' => jalali_to_carbon($this->date)->format('Y/m/d'),
            'start_time' => Carbon::createFromFormat('H:i', $this->start_time),
            'end_time' => Carbon::createFromFormat('H:i', $this->end_time),
        ]);
    }
}
