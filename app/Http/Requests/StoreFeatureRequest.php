<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFeatureRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Return validation rules.
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:features,name',
            'slug' => 'required|string|max:255|unique:features,slug',
            'description' => 'nullable|string',
            'value' => 'required|string',
            'resettable_period' => 'nullable|integer',
            'resettable_interval' => 'nullable|string',
            'sort_order' => 'nullable|integer',
        ];
    }
}
