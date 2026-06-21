<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PatchTractorTaskDataRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('tractor_task'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'consumed_water' => 'nullable|numeric|min:0',
            'consumed_fertilizer' => 'nullable|numeric|min:0',
            'consumed_poison' => 'nullable|numeric|min:0',
            'operation_area' => 'nullable|numeric|min:0',
            'workers_count' => 'nullable|integer|min:0',
        ];
    }
}
