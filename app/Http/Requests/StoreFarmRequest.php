<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Farm;

class StoreFarmRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Farm::class);
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
                    if ($user->farms()->where('name', $value)->exists()) {
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
