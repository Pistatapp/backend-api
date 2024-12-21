<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WeatherForecastRequest extends FormRequest
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
            'type' => 'required|string|in:current,forecast,history',
            'start_date' => [
                'nullable',
                'required_if:type,history',
                'date',
                Rule::when($this->type === 'history', ['before_or_equal:today', 'after_or_equal:' . now()->subDays(300)->toDateString()]),
            ],
            'end_date' => [
                'nullable',
                'required_if:type,history',
                'date',
                'after_or_equal:start_date',
                Rule::when($this->type === 'history', ['before_or_equal:today', 'after_or_equal:' . now()->subDays(300)->toDateString()]),
            ],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        if (in_array($this->type, ['history'])) {
            $this->merge([
                'start_date' => $this->start_date ? jalali_to_carbon($this->start_date) : null,
                'end_date' => $this->end_date ? jalali_to_carbon($this->end_date) : null,
            ]);
        }
    }
}
