<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\TractorTask;
use Illuminate\Validation\Rule;

class StoreTractorTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', TractorTask::class);
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
            'date' => [
                'required',
                'shamsi_date',
                Rule::unique('tractor_tasks')->where(function ($query) {
                    return $query->where('date', $this->date)
                        ->where('tractor_id', $this->tractor->id);
                }),
            ],
            'start_time' => 'required|date_format:H:i',
            'end_time' => [
                'required',
                'date_format:H:i',
                function ($attribute, $value, $fail) {
                    if (strtotime($value) <= strtotime($this->start_time)) {
                        $fail('The end time must be after the start time.');
                    }
                },
            ],
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
