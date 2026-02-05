<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        return [
            'name' => 'required|string|max:255',
            'username' => 'nullable|string|max:255|unique:users,username',
            'mobile' => 'required|ir_mobile:zero|unique:users,mobile',
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
                        $allowedRoles = ['operator', 'viewer', 'consultant'];
                    }

                    if (!in_array($value, $allowedRoles)) {
                        $fail(__('You do not have permission to assign this role.'));
                    }
                },
            ],
            'farms' => 'required|array|min:1',
            'farms.*' => [
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
    }
}
