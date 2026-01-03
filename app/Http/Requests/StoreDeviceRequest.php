<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasRole('root');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'device_type' => 'required|in:personal_gps,tractor_gps',
            'name' => 'required|string|max:255',
            'imei' => 'required|string|max:255|unique:gps_devices,imei',
            'sim_number' => 'nullable|string|max:255|unique:gps_devices,sim_number',
            'tractor_id' => 'nullable|exists:tractors,id|required_if:device_type,tractor_gps',
            'farm_id' => 'nullable|exists:farms,id',
        ];
    }
}
