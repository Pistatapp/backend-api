<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FilterTractorReportRequest extends FormRequest
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
            'tractor_id' => 'required|exists:tractors,id',
            'from_date' => 'nullable|required_with:to_date|shamsi_date',
            'to_date' => 'nullable|required_with:from_date|shamsi_date|after_or_equal:from_date',
            'operation_id' => 'nullable|exists:operations,id',
            'field_id' => 'nullable|exists:fields,id',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'from_date' => jalali_to_carbon($this->from_date),
            'to_date' => jalali_to_carbon($this->to_date),
        ]);
    }
}
