<?php

namespace App\Http\Requests\LoadEstimation;

use Illuminate\Foundation\Http\FormRequest;

class LoadEstimationTableUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rows' => 'required|array',
            'rows.*.condition' => 'required|string|in:excellent,good,normal,bad',
            'rows.*.fruit_cluster_weight' => 'required|numeric|min:0',
            'rows.*.average_bud_count' => 'nullable|integer|min:0',
            'rows.*.bud_to_fruit_conversion' => 'required|numeric|min:0',
            'rows.*.estimated_to_actual_yield_ratio' => 'required|numeric|min:0',
            'rows.*.tree_yield_weight_grams' => 'required|integer|min:0',
            'rows.*.tree_weight_kg' => 'nullable|integer|min:0',
            'rows.*.tree_count' => 'nullable|integer|min:0',
            'rows.*.total_garden_yield_kg' => 'nullable|numeric|min:0',
        ];
    }
}
