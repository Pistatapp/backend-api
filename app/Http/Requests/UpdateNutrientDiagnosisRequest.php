<?php

namespace App\Http\Requests;

use App\Models\NutrientDiagnosisRequest;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNutrientDiagnosisRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var NutrientDiagnosisRequest $diagnosisRequest */
        $diagnosisRequest = $this->route('request');

        return $this->user()->can('update', $diagnosisRequest);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'samples' => 'required|array|min:1',
            'samples.*.field_id' => 'required|exists:fields,id',
            'samples.*.field_area' => 'nullable|numeric|min:0',
            'samples.*.load_amount' => 'required|numeric|min:0',
            'samples.*.nitrogen' => 'required|numeric|min:0',
            'samples.*.phosphorus' => 'required|numeric|min:0',
            'samples.*.potassium' => 'required|numeric|min:0',
            'samples.*.calcium' => 'required|numeric|min:0',
            'samples.*.magnesium' => 'required|numeric|min:0',
            'samples.*.iron' => 'required|numeric|min:0',
            'samples.*.copper' => 'required|numeric|min:0',
            'samples.*.zinc' => 'required|numeric|min:0',
            'samples.*.boron' => 'required|numeric|min:0',
        ];
    }
}
