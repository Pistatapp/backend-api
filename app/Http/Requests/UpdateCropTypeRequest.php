<?php

namespace App\Http\Requests;

use App\Rules\LoadEstimationData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCropTypeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $cropType = $this->route('crop_type');

        if ($this->user()->hasRole('root')) {
            return $cropType->isGlobal();
        }

        if ($this->user()->hasRole('admin')) {
            return $cropType->isOwnedBy($this->user()->id);
        }

        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $cropType = $this->route('crop_type');

        return [
            'name' => 'required|string|max:255|unique:crop_types,name,' . $cropType->id . ',id',
            'standard_day_degree' => 'nullable|numeric',
            'is_active' => 'boolean',
            'load_estimation_data' => ['nullable', 'array', new LoadEstimationData],
            'load_estimation_code' => [
                'nullable',
                'string',
                'max:64',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('crop_types', 'load_estimation_code')->ignore($cropType->id),
            ],
        ];
    }
}
