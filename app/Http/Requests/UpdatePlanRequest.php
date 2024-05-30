<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Plan;

class UpdatePlanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
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
            'goal' => 'required|string|max:2000',
            'referrer' => 'required|string|max:255',
            'counselors' => 'required|string|max:255',
            'executors' => 'required|string|max:255',
            'statistical_counselors' => 'required|string|max:255',
            'implementation_location' => 'required|string|max:255',
            'used_materials' => 'required|string|max:255',
            'evaluation_criteria' => 'required|string|max:255',
            'description' => 'required|string|max:2000',
            'start_date' => [
                'required',
                'date',
                function ($attribute, $value, $fail) {
                    $existingPlan = Plan::where('id', '!=', $this->route('plan')->id)
                        ->where('start_date', '<=', $value)
                        ->where('end_date', '>=', $value)
                        ->first();
                    if ($existingPlan) {
                        $fail('The selected start date interferes with an existing plan.');
                    }
                },
            ],
            'end_date' => [
                'required',
                'date',
                function ($attribute, $value, $fail) {
                    $existingPlan = Plan::where('id', '!=', $this->route('plan')->id)
                        ->where('start_date', '<=', $value)
                        ->where('end_date', '>=', $value)
                        ->first();
                    if ($existingPlan) {
                        $fail('The selected end date interferes with an existing plan.');
                    }
                },
            ],
            'features' => 'required|array|min:1',
            'features.*.timar_id' => 'required|integer|exists:timars,id',
            'features.*.timarables' => 'required|array|min:1',
            'features.*.timarables.*.timarable_id' => 'required|integer',
            'features.*.timarables.*.timarable_type' => 'required|string|in:field,row,tree',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'start_date' => jalali_to_carbon($this->start_date),
            'end_date' => jalali_to_carbon($this->end_date),
        ]);
    }
}
