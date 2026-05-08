<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppReleaseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole('root') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'max:50', 'unique:app_releases,version', 'regex:/^v\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/'],
            'file' => ['required', 'file', 'max:512000'],
            'release_notes' => ['nullable', 'string', 'max:20000'],
        ];
    }
}
