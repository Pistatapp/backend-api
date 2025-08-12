<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\UniqueTractorTask;

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
            'operation_id' => 'required|integer|exists:operations,id',
            'field_id' => 'required|integer|exists:fields,id',
            'date' => [
                'required',
                'shamsi_date',
            ],
            'start_time' => [
                'required',
                'date_format:H:i',
                new UniqueTractorTask,
            ],
            'end_time' => [
                'required',
                'date_format:H:i',
                new UniqueTractorTask,
                function ($attribute, $value, $fail) {
                    if (strtotime($value) <= strtotime($this->start_time)) {
                        $fail('The end time must be after the start time.');
                    }
                },
            ],
            'data' => 'nullable|array',
            'data.consumed_water' => 'nullable|numeric|min:0',
            'data.consumed_fertilizer' => 'nullable|numeric|min:0',
            'data.consumed_poison' => 'nullable|numeric|min:0',
            'data.operation_area' => 'nullable|numeric|min:0',
            'data.workers_count' => 'nullable|integer|min:0',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        try {
            $this->merge([
                'date' => jalali_to_carbon($this->date),
            ]);
        } catch (\Exception $e) {
            throw new \Exception('Error: ' . $e->getMessage());
        }
    }
}
