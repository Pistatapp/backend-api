<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MobileConnectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Mobile app connection requests don't require authentication.
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
            'mobile_number' => 'required|string|max:20',
            'device_fingerprint' => 'required|string|max:255',
            'device_info' => 'nullable|array',
            'device_info.model' => 'nullable|string',
            'device_info.os_version' => 'nullable|string',
            'device_info.app_version' => 'nullable|string',
        ];
    }
}
