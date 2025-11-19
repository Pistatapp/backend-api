<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Ticket;
use Carbon\Carbon;

class ReportErrorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Ticket::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'error_message' => 'required|string|max:5000',
            'error_trace' => 'nullable|string|max:10000',
            'page_path' => 'nullable|string|max:500',
            'app_version' => 'nullable|string|max:50',
            'device_model' => 'nullable|string|max:100',
            'occurred_at' => 'nullable|date',
            'message' => 'nullable|string|max:1000', // Optional user message
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $occurredAt = $this->filled('occurred_at') 
            ? Carbon::parse($this->occurred_at) 
            : now();

        $this->merge([
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'error_message.required' => __('The error message field is required.'),
            'error_message.max' => __('The error message may not be greater than 5000 characters.'),
            'error_trace.max' => __('The error trace may not be greater than 10000 characters.'),
            'page_path.max' => __('The page path may not be greater than 500 characters.'),
            'app_version.max' => __('The app version may not be greater than 50 characters.'),
            'device_model.max' => __('The device model may not be greater than 100 characters.'),
        ];
    }
}

