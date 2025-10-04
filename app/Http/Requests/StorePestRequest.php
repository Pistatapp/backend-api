<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePestRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:255',
                'unique:pests,name'
            ],
            'scientific_name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'damage' => 'nullable|string',
            'management' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'standard_day_degree' => 'nullable|numeric|min:0|max:100',
        ];
    }
}
