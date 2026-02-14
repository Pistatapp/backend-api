<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MobileGpsDataRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Mobile GPS data submission is validated by device fingerprint, not authentication.
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
            'device_fingerprint' => 'required|string|max:255',
            'labour_id' => 'required|exists:labours,id',
            'mobile_number' => 'nullable|string|max:20',
            'imei' => 'nullable|string|max:255',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'altitude' => 'nullable|numeric',
            'speed' => 'nullable|numeric|min:0',
            'time' => 'required|integer',
            'status' => 'nullable|integer|in:0,1', // 0 = stop, 1 = movement
        ];
    }
}
