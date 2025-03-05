<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFarmRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('farm'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    $user = $this->user();
                    if ($user->farms()->where('name', $value)->where('id', '!=', $this->route('farm')->id)->exists()) {
                        $fail(__('validation.unique', ['attribute' => $attribute]));
                    }
                },
            ],
            'coordinates' => 'required|array|min:3',
            'coordinates.*' => 'required|string',
            'center' => 'required|string|regex:/^(\-?\d+(\.\d+)?),\s*(\-?\d+(\.\d+)?)$/',
            'zoom' => 'required|numeric|min:1',
            'area' => 'required|numeric|min:0',
            'crop_id' => 'required|exists:crops,id',
        ];
    }
}
