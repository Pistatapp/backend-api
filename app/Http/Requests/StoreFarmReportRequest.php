<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\FarmReport;

class StoreFarmReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', FarmReport::class);
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
            'reportables' => 'required|array|min:1',
            'reportables.*.type' => 'required|string|in:farm,field,plot,row,tree',
            'reportables.*.id' => 'required|integer',
            'include_sub_items' => 'boolean',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'date' => jalali_to_carbon($this->date),
        ]);
    }
}
