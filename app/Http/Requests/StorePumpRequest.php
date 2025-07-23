<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePumpRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('pumps')->where(function ($query) {
                    return $query->where('farm_id', $this->route('farm')->id);
                })
            ],
            'serial_number' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'manufacturer' => 'required|string|max:255',
            'horsepower' => 'required|numeric|min:0|max:1000',
            'phase' => 'required|numeric|min:1|max:3',
            'voltage' => 'required|numeric|min:0|max:10000',
            'ampere' => 'required|numeric|min:0|max:1000',
            'rpm' => 'required|numeric|min:0|max:10000',
            'pipe_size' => 'required|numeric|min:0|max:1000',
            'debi' => 'required|numeric|min:0|max:10000',
            'location' => 'required|string|max:255',
        ];
    }
}
