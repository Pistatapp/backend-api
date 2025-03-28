<?php

namespace App\Http\Requests\LoadEstimation;

use Illuminate\Foundation\Http\FormRequest;

class LoadEstimateRequest extends FormRequest
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
            'field_id' => 'required|integer|exists:fields,id',
            'average_bud_count' => 'required|integer|min:0',
            'tree_count' => 'required|integer|min:0',
        ];
    }
}
