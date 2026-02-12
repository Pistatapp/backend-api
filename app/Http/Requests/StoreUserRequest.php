<?php

namespace App\Http\Requests;

use App\Rules\AllowedFarmAssignment;
use App\Rules\AllowedRoleAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('manage-users');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $attendenceTrackingEnabled = $this->boolean('attendence_tracking_enabled');

        $rules = [
            'name' => 'required|string|max:255',
            'mobile' => 'required|ir_mobile:zero|unique:users,mobile',
            'role' => ['required', 'string', 'exists:roles,name', new AllowedRoleAssignment()],
            'farm_id' => ['required', 'exists:farms,id', new AllowedFarmAssignment()],
        ];

        // Add attendence tracking specific validation rules
        if ($attendenceTrackingEnabled) {
            $rules = array_merge($rules, [
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
                'hourly_wage' => 'required|integer|min:1',
                'overtime_hourly_wage' => 'required|integer|min:1',
                'imei' => 'nullable|string|max:255',
            ]);
            $rules['image'] = 'nullable|image|max:1024';
            $rules['attendence_tracking_enabled'] = 'required|boolean';
        }

        return $rules;
    }
}
