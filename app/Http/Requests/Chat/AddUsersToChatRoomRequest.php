<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class AddUsersToChatRoomRequest extends FormRequest
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
            'user_ids' => [
                'required',
                'array',
                'min:1',
                function ($attribute, $value, $fail) {
                    $chatRoom = $this->route('chatRoom');
                    $farm = $chatRoom->farm;

                    // Check if all users are in the same farm
                    $farmUserIds = $farm->users->pluck('id')->toArray();
                    $invalidUsers = array_diff($value, $farmUserIds);

                    if (!empty($invalidUsers)) {
                        $fail('Some selected users are not members of this farm.');
                    }
                },
            ],
            'user_ids.*' => 'required|exists:users,id',
        ];
    }
}

