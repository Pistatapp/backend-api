<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceRequest extends FormRequest
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
        $device = $this->route('device');
        $deviceId = $device ? $device->id : null;

        return [
            'name' => 'sometimes|string|max:255',
            'imei' => 'sometimes|string|max:255|unique:gps_devices,imei,' . $deviceId,
            'sim_number' => 'nullable|string|max:255|unique:gps_devices,sim_number,' . $deviceId,
        ];
    }
}
