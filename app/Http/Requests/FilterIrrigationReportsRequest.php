<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FilterIrrigationReportsRequest extends FormRequest
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
            'plot_id' => 'required|integer|exists:plots,id',
            'valves' => 'nullable|array|min:1',
            'valves.*' => 'nullable|integer|exists:valves,id',
            'labour_id' => 'nullable|integer|exists:labours,id',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date'
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        $dates = ['from_date' => 'from_date', 'to_date' => 'to_date'];

        foreach ($dates as $input => $output) {
            if ($this->$input) {
                $this->merge([
                    $output => jalali_to_carbon($this->$input),
                ]);
            }
        }
    }
}
