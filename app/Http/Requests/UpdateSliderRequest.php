<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSliderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('slider'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|unique:sliders,name,' . $this->route('slider')->id,
            'images' => 'required|array',
            'images.*.sort_order' => 'required|integer',
            'images.*.file' => 'nullable|image|max:2048',
            'page' => 'required|string',
            'is_active' => 'required|boolean',
            'interval' => 'required|integer|min:1',
        ];
    }
}
