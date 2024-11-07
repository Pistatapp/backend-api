<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FrostbitePredictionRequest extends FormRequest
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
            'type' => ['required', Rule::in(['normal', 'radiational'])],
            'start_dt' => [
                'nullable',
                Rule::requiredIf($this->type === 'normal'),
                Rule::prohibitedIf($this->type === 'radiational'),
                'date',
            ],
            'end_dt' => [
                'nullable',
                Rule::requiredIf($this->type === 'normal'),
                Rule::prohibitedIf($this->type === 'radiational'),
                'date',
            ],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'start_dt' => $this->start_dt ? jalali_to_carbon($this->start_dt) : null,
            'end_dt' => $this->end_dt ? jalali_to_carbon($this->end_dt) : null,
        ]);
    }
}
