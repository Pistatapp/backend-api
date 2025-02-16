<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\TrucktorTask;

class StoreTrucktorTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', TrucktorTask::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'operation_id' => 'required|integer|exists:operations,id',
            'field_ids' => 'required|array|min:1',
            'field_ids.*' => 'integer|exists:fields,id',
            'name' => 'required|string|max:255',
            'start_date' => [
                'required',
                'date',
                'after_or_equal:' . now()->toDateString(),
                new \App\Rules\UniqueTrucktorTask(),
            ],
            'end_date' => [
                'required',
                'date',
                'after_or_equal:start_date',
                new \App\Rules\UniqueTrucktorTask(),
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
