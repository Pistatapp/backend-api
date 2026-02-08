<?php

namespace App\Http\Requests;

use App\Rules\LoadEstimationData;
use Illuminate\Foundation\Http\FormRequest;

class StoreCropTypeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['root', 'admin']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:crop_types,name',
            'standard_day_degree' => 'nullable|numeric',
            'load_estimation_data' => ['nullable', 'array', new LoadEstimationData],
        ];
    }
}
