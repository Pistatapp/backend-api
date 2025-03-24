<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserPreferencesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // User must be authenticated via middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'preferences' => 'sometimes|array',
            'preferences.language' => 'sometimes|string|in:en,fa',
            'preferences.theme' => 'sometimes|string|in:light,dark',
            'preferences.notifications_enabled' => 'sometimes|boolean',
            'preferences.working_environment' => 'sometimes|string|nullable',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'preferences.language.in' => __('messages.preferences.invalid_language'),
            'preferences.theme.in' => __('messages.preferences.invalid_theme'),
        ];
    }
}
