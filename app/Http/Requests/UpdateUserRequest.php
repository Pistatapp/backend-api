<?php

namespace App\Http\Requests;

use App\Rules\AllowedFarmAssignment;
use App\Rules\AllowedRoleAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $attendanceTrackingEnabled = $this->boolean('attendance_tracking_enabled');

        $rules = [
            'name' => 'required|string|max:255',
            'mobile' => 'required|ir_mobile:zero|unique:users,mobile,' . $this->route('user')->id,
            'role' => ['required', 'string', 'exists:roles,name', new AllowedRoleAssignment()],
            'farm_id' => ['required', 'exists:farms,id', new AllowedFarmAssignment()]
        ];

        // Add labour-specific validation rules
        if ($attendanceTrackingEnabled) {
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
                'tracking_device' => 'required|array',
                'tracking_device.type' => 'required|string|in:mobile_phone,personal_gps',
                'tracking_device.device_fingerprint' => [
                    Rule::requiredIf(fn () => ($this->input('tracking_device.type') ?? null) === 'mobile_phone'),
                    'nullable',
                    'string',
                    'min:1',
                    'max:255',
                ],
                'tracking_device.sim_number' => 'required|string|ir_mobile:zero',
                'tracking_device.imei' => 'required|string|size:15|regex:/^[0-9]{15}$/',
            ]);
            $rules['image'] = 'nullable|image|max:1024';
            $rules['attendance_tracking_enabled'] = 'required|boolean';
        }

        return $rules;
    }
}
