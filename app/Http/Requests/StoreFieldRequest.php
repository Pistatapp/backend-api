<?php

namespace App\Http\Requests;

use App\Models\Field;
use Illuminate\Foundation\Http\FormRequest;

class StoreFieldRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Field::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'coordinates' => 'required|array|min:3',
            'coordinates.*' => 'required|string|regex:/^\d+,\d+$/',
            'center' => 'required|string|max:255|regex:/^\d+,\d+$/',
            'area' => 'required|numeric|min:0',
            'products' => 'nullable|array|min:1',
        ];
    }
}
