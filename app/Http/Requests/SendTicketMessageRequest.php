<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Ticket;

class SendTicketMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');
        return $this->user()->can('reply', $ticket);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message' => 'required|string|max:1000',
            'attachments' => 'nullable|array|max:3',
            'attachments.*' => 'required|file|mimes:png,jpg,jpeg,pdf|max:5120', // 5MB max
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
            'message.required' => __('The message field is required.'),
            'message.max' => __('The message may not be greater than 1000 characters.'),
            'attachments.max' => __('You may upload a maximum of 3 files.'),
            'attachments.*.file' => __('Each attachment must be a valid file.'),
            'attachments.*.mimes' => __('Attachments must be PNG, JPG, JPEG, or PDF files.'),
            'attachments.*.max' => __('Each attachment may not be greater than 5MB.'),
        ];
    }
}

