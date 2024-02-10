<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLaborRequest extends FormRequest
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
            'type' => 'required|string|max:255|in:daily_laborer,project_laborer,permanent_laborer',
            'fname' => 'required|string|max:255',
            'lname' => 'required|string|max:255',
            'national_id' => 'required|ir_national_code|max:255|unique:labors,national_id,' . $this->labor->id,
            'mobile' => 'required|ir_mobile|unique:labors,mobile,' . $this->labor->id,
            'position' => 'required|string|max:255',
            'project_start_date' => [
                'nullable',
                'shamsi_date',
                'before_or_equal:project_end_date',
                'required_with:project_end_date',
                Rule::requiredIf(function () {
                    return in_array($this->type, ['project_laborer']);
                }),
                Rule::prohibitedIf(function () {
                    return in_array($this->type, ['daily_laborer', 'permanent_laborer']);
                }),
            ],
            'project_end_date' => [
                'nullable',
                'shamsi_date',
                'after_or_equal:project_start_date',
                'required_with:project_start_date',
                Rule::requiredIf(function () {
                    return in_array($this->type, ['project_laborer']);
                }),
                Rule::prohibitedIf(function () {
                    return in_array($this->type, ['daily_laborer', 'permanent_laborer']);
                }),
            ],
            'work_type' => 'required|string|max:255',
            'work_days' => [
                'nullable',
                'integer',
                'between:1,7',
                Rule::requiredIf(function () {
                    return in_array($this->type, ['daily_laborer', 'permanent_laborer']);
                }),
                Rule::prohibitedIf(function () {
                    return in_array($this->type, ['project_laborer']);
                }),
            ],
            'work_hours' => [
                'nullable',
                'integer',
                'between:1,24',
                Rule::requiredIf(function () {
                    return in_array($this->type, ['daily_laborer', 'permanent_laborer']);
                }),
                Rule::prohibitedIf(function () {
                    return in_array($this->type, ['project_laborer']);
                }),
            ],
            'start_work_time' => [
                'nullable',
                'date_format:H:i',
                Rule::requiredIf(function () {
                    return in_array($this->type, ['daily_laborer', 'permanent_laborer']);
                }),
                Rule::prohibitedIf(function () {
                    return in_array($this->type, ['project_laborer']);
                }),
            ],
            'end_work_time' => [
                'nullable',
                'date_format:H:i',
                Rule::requiredIf(function () {
                    return in_array($this->type, ['daily_laborer', 'permanent_laborer']);
                }),
                Rule::prohibitedIf(function () {
                    return in_array($this->type, ['project_laborer']);
                }),
            ],
            'salary' => [
                'nullable',
                'integer',
                Rule::requiredIf(function () {
                    return in_array($this->type, ['project_laborer']);
                }),
                Rule::prohibitedIf(function () {
                    return in_array($this->type, ['daily_laborer', 'permanent_laborer']);
                }),
            ],
            'daily_salary' => [
                'nullable',
                'integer',
                Rule::requiredIf(function () {
                    return in_array($this->type, ['daily_laborer']);
                }),
                Rule::prohibitedIf(function () {
                    return in_array($this->type, ['project_laborer', 'permenent_laborer']);
                }),
            ],
            'monthly_salary' => [
                'nullable',
                'integer',
                Rule::requiredIf(function () {
                    return in_array($this->type, ['permanent_laborer']);
                }),
                Rule::prohibitedIf(function () {
                    return in_array($this->type, ['project_laborer', 'daily_laborer']);
                }),
            ],
        ];
    }
}
