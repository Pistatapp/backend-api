<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlanRequest extends FormRequest
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
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'slug' => 'required|string|max:255|unique:plans,slug,' . $this->route('plan')->id,
            'name' => 'required|string|max:255|unique:plans,name,' . $this->route('plan')->id,
            'description' => 'nullable|string|max:2000',
            'is_active' => 'required|boolean',
            'price' => 'required|numeric',
            'signup_fee' => 'nullable|numeric',
            'currency' => 'required|string|max:3',
            'trial_period' => 'nullable|integer',
            'trial_interval' => 'nullable|string',
            'invoice_period' => 'required|integer',
            'invoice_interval' => 'required|string',
            'grace_period' => 'nullable|integer',
            'grace_interval' => 'nullable|string',
            'prorate_day' => 'nullable|integer',
            'prorate_period' => 'nullable|integer',
            'prorate_extend_due' => 'nullable|integer',
            'active_subscribers_limit' => 'nullable|integer',
            'sort_order' => 'nullable|integer',
        ];
    }
}
