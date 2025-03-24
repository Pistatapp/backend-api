<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNutrientDiagnosisRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Only allows if the user has access to the farm.
     */
    public function authorize(): bool
    {
        $farm = $this->route('farm');
        return $farm->users->contains($this->user()->id);
    }

    /**
     * Get the validation rules that apply to the request.
     * Validates nutrient sample data including field area and nutrient levels.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'samples' => 'required|array|min:1',
            'samples.*.field_id' => 'required|exists:fields,id',
            'samples.*.field_area' => 'required|numeric|min:0',
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

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'samples.required' => 'حداقل یک نمونه باید وارد شود',
            'samples.*.field_id.required' => 'انتخاب قطعه الزامی است',
            'samples.*.field_id.exists' => 'قطعه انتخاب شده معتبر نیست',
            'samples.*.field_area.required' => 'مساحت قطعه الزامی است',
            'samples.*.load_amount.required' => 'میزان بار الزامی است',
            'samples.*.nitrogen.required' => 'مقدار ازت الزامی است',
            'samples.*.phosphorus.required' => 'مقدار فسفر الزامی است',
            'samples.*.potassium.required' => 'مقدار پتاس الزامی است',
            'samples.*.calcium.required' => 'مقدار کلسیم الزامی است',
            'samples.*.magnesium.required' => 'مقدار منگنز الزامی است',
            'samples.*.iron.required' => 'مقدار آهن الزامی است',
            'samples.*.copper.required' => 'مقدار مس الزامی است',
            'samples.*.zinc.required' => 'مقدار روی الزامی است',
            'samples.*.boron.required' => 'مقدار بور الزامی است',
        ];
    }
}
