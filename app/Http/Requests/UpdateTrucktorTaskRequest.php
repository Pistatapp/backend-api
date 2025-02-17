<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTractorTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('tractor_task'));
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
            'start_date' => [
                'required',
                'date',
                'after_or_equal:' . now()->toDateString(),
                new \App\Rules\UniquetractorTask(),
            ],
            'end_date' => [
                'required',
                'date',
                'after_or_equal:start_date',
                new \App\Rules\UniquetractorTask(),
            ],
            'description' => 'nullable|string|max:5000',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        try {
            $this->merge([
                'start_date' => jalali_to_carbon($this->start_date),
                'end_date' => jalali_to_carbon($this->end_date),
            ]);
        } catch (\Exception $e) {
            throw new \Exception('Error: ' . $e->getMessage());
        }
    }
}
