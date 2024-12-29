<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFarmReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('farmReport'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => 'required|date',
            'operation_id' => 'required|exists:operations,id',
            'labour_id' => 'required|exists:labours,id',
            'description' => 'required|string',
            'value' => 'required|numeric',
            'reportable_type' => 'required|string|in:farm,field,row,tree',
            'reportable_id' => 'required|integer',
        ];
    }
}
