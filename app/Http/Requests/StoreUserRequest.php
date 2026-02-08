<?php

namespace App\Http\Requests;

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
        $isLabour = $this->role === 'labour';

        $rules = [
            'name' => 'required|string|max:255',
            'mobile' => $isLabour
                ? [
                    'required',
                    'ir_mobile:zero',
                    'unique:labours,mobile',
                    'unique:users,mobile',
                ]
                : 'required|ir_mobile:zero|unique:users,mobile',
            'role' => [
                'required',
                'exists:roles,name',
                function ($attribute, $value, $fail) {
                    $user = $this->user();
                    $allowedRoles = [];

                    if ($user->hasRole('root')) {
                        $allowedRoles = ['super-admin', 'admin'];
                    } elseif ($user->hasRole('super-admin')) {
                        $allowedRoles = ['admin', 'consultant', 'inspector'];
                    } elseif ($user->hasRole('admin')) {
                        $allowedRoles = ['operator', 'viewer', 'consultant', 'labour', 'employee'];
                    }

                    if (!in_array($value, $allowedRoles)) {
                        $fail(__('You do not have permission to assign this role.'));
                    }
                },
            ],
            'farm_id' => [
                'required',
                'exists:farms,id',
                function ($attribute, $value, $fail) {
                    $user = $this->user();
                    $farm = \App\Models\Farm::find($value);

                    if (!$farm || !$farm->users->contains($user)) {
                        $fail(__('You do not have permission to assign access to this farm.'));
                    }
                },
            ],
        ];

        // Add labour-specific validation rules
        if ($isLabour) {
            $rules = array_merge($rules, [
                'personnel_number' => 'nullable|string|max:255|unique:labours,personnel_number',
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
                'image' => 'nullable|image|max:1024',
                'attendence_tracking_enabled' => 'required|boolean',
                'imei' => 'nullable|string|max:255',
            ]);
        }

        return $rules;
    }
}
