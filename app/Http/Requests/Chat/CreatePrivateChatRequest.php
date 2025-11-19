<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class CreatePrivateChatRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled in controller
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    $farm = $this->route('farm');
                    $user = $this->user();

                    // Check if target user is in the same farm
                    if (!$farm->users->contains($value)) {
                        $fail('The selected user is not a member of this farm.');
                    }

                    // Check if user is trying to chat with themselves
                    if ($value == $user->id) {
                        $fail('You cannot create a private chat with yourself.');
                    }
                },
            ],
        ];
    }
}

