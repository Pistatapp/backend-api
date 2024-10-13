<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VolkOilSprayRequest extends FormRequest
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
            'start_dt' => [
                'required',
                'shamsi_date',
                function ($attribute, $value, $fail) {
                    $query = \App\Models\VolkOilSpray::where('start_dt', '<=', $value)
                        ->where('end_dt', '>=', $value);

                    if ($this->method() === 'PUT') {
                        // If this is an update request, exclude the current notification from the check
                        $query->where('id', '!=', $this->route('volk_oil_spray')->id);
                    }

                    if ($query->exists()) {
                        $fail(__('A Volk Spray Notification is already defined within this date range.'));
                    }
                },
            ],
            'end_dt' => [
                'required',
                'shamsi_date',
                function ($attribute, $value, $fail) {
                    $query = \App\Models\VolkOilSpray::where('start_dt', '<=', $value)
                        ->where('end_dt', '>=', $value);

                    if ($this->method() === 'PUT') {
                        // If this is an update request, exclude the current notification from the check
                        $query->where('id', '!=', $this->route('volk_oil_spray')->id);
                    }

                    if ($query->exists()) {
                        $fail(__('A Volk Spray Notification is already defined within this date range.'));
                    }
                },
            ],
            'min_temp' => 'nullable|numeric',
            'max_temp' => 'nullable|numeric',
            'cold_requirement' => 'required|numeric',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'start_dt' => $this->start_dt ? jalali_to_carbon($this->start_dt)->format('Y-m-d') : null,
            'end_dt' => $this->end_dt ? jalali_to_carbon($this->end_dt)->format('Y-m-d') : null,
        ]);
    }
}
