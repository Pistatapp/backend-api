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
        return $this->user()?->hasRole('root') === true;
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
            'file' => ['required', 'array', 'min:1'],
            'file.name' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+\.apk$/i'],
            'file.path' => [
                'required',
                'string',
                'max:255',
                'regex:/^(?:upload\/[A-Za-z0-9-]+|app-releases\/staging)\/[0-9]{4}-[0-9]{2}-[0-9]{2}\/$/',
            ],
            'file.mime_type' => [
                'required',
                'string',
                'max:255',
                'in:application/vnd.android.package-archive,application/octet-stream,application-octet-stream',
            ],
            'version_code' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'release_notes' => ['nullable', 'string', 'max:20000'],
        ];
    }
}
