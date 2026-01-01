<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLabourRequest extends FormRequest
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
            'team_id' => 'nullable|integer|exists:teams,id',
            'fname' => 'required|string|max:255',
            'lname' => 'required|string|max:255',
            'national_id' => 'required|unique:labours,national_id,' . $this->labour->id,
            'mobile' => 'required|ir_mobile|unique:labours,mobile,' . $this->labour->id,
            'work_type' => 'required|string|in:administrative,shift_based',
            'work_days' => [
                'nullable',
                'array',
                Rule::requiredIf(fn () => $this->work_type === 'administrative'),
                Rule::prohibitedIf(fn () => $this->work_type === 'shift_based'),
            ],
            'work_hours' => [
                'nullable',
                'numeric',
                'between:1,24',
                Rule::requiredIf(fn () => $this->work_type === 'administrative'),
            ],
            'start_work_time' => [
                'nullable',
                'date_format:H:i',
                Rule::requiredIf(fn () => $this->work_type === 'administrative'),
                Rule::prohibitedIf(fn () => $this->work_type === 'shift_based'),
            ],
            'end_work_time' => [
                'nullable',
                'date_format:H:i',
                'after:start_work_time',
                Rule::requiredIf(fn () => $this->work_type === 'administrative'),
                Rule::prohibitedIf(fn () => $this->work_type === 'shift_based'),
            ],
            'monthly_salary' => 'nullable|integer',
            'hourly_wage' => [
                'nullable',
                'integer',
                Rule::requiredIf(fn () => $this->work_type === 'shift_based'),
            ],
            'overtime_hourly_wage' => 'nullable|integer',
            'user_id' => 'nullable|exists:users,id',
        ];
    }
}

