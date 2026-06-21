<?php

namespace App\Http\Requests;

class UpdateTractorTaskRequest extends TractorTaskBaseRequest
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
        return array_merge($this->sharedTaskRules(), [
            'data' => 'nullable|array',
            'data.consumed_water' => 'nullable|numeric|min:0',
            'data.consumed_fertilizer' => 'nullable|numeric|min:0',
            'data.consumed_poison' => 'nullable|numeric|min:0',
            'data.operation_area' => 'nullable|numeric|min:0',
            'data.workers_count' => 'nullable|integer|min:0',
        ]);
    }
}
