<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GpsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->input('data', []);

        if (! is_array($data)) {
            return;
        }

        $filtered = array_values(array_filter($data, function ($item) {
            return is_array($item) && $item !== [];
        }));

        $this->merge(['data' => $filtered]);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'data' => 'required|array|min:1',
            'data.*.imei' => 'required|string|max:20',
            'data.*.coordinate' => 'required|array|size:2',
            'data.*.coordinate.0' => 'required|numeric|between:-90,90',
            'data.*.coordinate.1' => 'required|numeric|between:-180,180',
            'data.*.date_time' => 'required|date',
            'data.*.speed' => 'required|integer|min:0',
            'data.*.status' => 'required|integer|in:0,1',
            'data.*.directions' => 'required|array',
            'data.*.directions.ew' => 'required|integer',
            'data.*.directions.ns' => 'required|integer',
        ];
    }
}
