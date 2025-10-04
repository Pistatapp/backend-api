<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachUserToFarmRequest extends FormRequest
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
            'user_id' => 'required|exists:users,id',
            'role' => [
                'required',
                'string',
                Rule::notIn(['admin', 'root', 'super-admin']),
            ],
            'permissions' => [
                'nullable',
                'array',
                'required_if:role,custom-role',
            ],
            'permissions.*' => [
                'string',
                'exists:permissions,name',
            ],
        ];
    }


    /**
     * Get the validated permissions for custom-role.
     *
     * @return array
     */
    public function getValidatedPermissions(): array
    {
        if ($this->input('role') === 'custom-role') {
            return $this->input('permissions', []);
        }

        return [];
    }
}
