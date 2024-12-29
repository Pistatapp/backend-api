<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdatePermissionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'name' => Str::slug(preg_replace('/[^A-Za-z0-9\-\s]/', '', $this->name), '_'),
        ]);
    }

    public function rules()
    {
        return [
            'name' => ['required', 'string', Rule::unique('permissions', 'name')->ignore($this->permission)],
            'persian_name' => 'required|string',
            'guard_name' => 'required|string',
        ];
    }
}
