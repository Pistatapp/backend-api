<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $pest = $this->route('pest');

        // Root users can only update global pests
        if ($this->user()->hasRole('root')) {
            return $pest->isGlobal();
        }

        // Admin users can only update their own pests
        if ($this->user()->hasRole('admin')) {
            return $pest->isOwnedBy($this->user()->id);
        }

        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $pest = $this->route('pest');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                'unique:pests,name,' . $pest->id
            ],
            'scientific_name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'damage' => 'nullable|string',
            'management' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'standard_day_degree' => 'nullable|numeric|min:0|max:100',
        ];
    }
}
