<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSliderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Slider::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|unique:sliders',
            'images' => 'required|array|min:1',
            'images.*.sort_order' => 'required|integer|min:0',
            'images.*.file' => 'required|image|max:5120',
            'page' => 'required|string',
            'is_active' => 'required|boolean',
            'interval' => 'required|integer|min:1',
        ];
    }
}
