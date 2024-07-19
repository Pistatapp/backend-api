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
            'type' => 'required|string|max:255|in:daily_labourer,project_labourer,permanent_labourer',
            'fname' => 'required|string|max:255',
            'lname' => 'required|string|max:255',
            'national_id' => 'required|ir_national_code|unique:labours,national_id,' . $this->labour->id,
            'mobile' => 'required|ir_mobile|unique:labours,mobile,' . $this->labour->id,
            'position' => 'required|string|max:255',
            'project_start_date' => [
                'nullable',
                'shamsi_date',
                'before_or_equal:project_end_date',
                'required_with:project_end_date',
                Rule::requiredIf(fn () => $this->type === 'project_labourer'),
                Rule::prohibitedIf(fn () => in_array($this->type, ['daily_labourer', 'permanent_labourer'])),
            ],
            'project_end_date' => [
                'nullable',
                'shamsi_date',
                'after_or_equal:project_start_date',
                'required_with:project_start_date',
                Rule::requiredIf(fn () => $this->type === 'project_labourer'),
                Rule::prohibitedIf(fn () => in_array($this->type, ['daily_labourer', 'permanent_labourer'])),
            ],
            'work_type' => 'required|string|max:255',
            'work_days' => [
                'nullable',
                'integer',
                'between:1,7',
                Rule::requiredIf(fn () => in_array($this->type, ['daily_labourer', 'permanent_labourer'])),
                Rule::prohibitedIf(fn () => $this->type === 'project_labourer'),
            ],
            'work_hours' => [
                'nullable',
                'integer',
                'between:1,24',
                Rule::requiredIf(fn () => in_array($this->type, ['daily_labourer', 'permanent_labourer'])),
                Rule::prohibitedIf(fn () => $this->type === 'project_labourer'),
            ],
            'start_work_time' => [
                'nullable',
                'date_format:H:i',
                Rule::requiredIf(fn () => in_array($this->type, ['daily_labourer', 'permanent_labourer'])),
                Rule::prohibitedIf(fn () => $this->type === 'project_labourer'),
            ],
            'end_work_time' => [
                'nullable',
                'date_format:H:i',
                Rule::requiredIf(fn () => in_array($this->type, ['daily_labourer', 'permanent_labourer'])),
                Rule::prohibitedIf(fn () => $this->type === 'project_labourer'),
            ],
            'salary' => [
                'nullable',
                'integer',
                Rule::requiredIf(fn () => $this->type === 'project_labourer'),
                Rule::prohibitedIf(fn () => in_array($this->type, ['daily_labourer', 'permanent_labourer'])),
            ],
            'daily_salary' => [
                'nullable',
                'integer',
                Rule::requiredIf(fn () => $this->type === 'daily_labourer'),
                Rule::prohibitedIf(fn () => in_array($this->type, ['project_labourer', 'permanent_labourer'])),
            ],
            'monthly_salary' => [
                'nullable',
                'integer',
                Rule::requiredIf(fn () => $this->type === 'permanent_labourer'),
                Rule::prohibitedIf(fn () => in_array($this->type, ['project_labourer', 'daily_labourer'])),
            ],
        ];
    }
}
