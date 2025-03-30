<?php

namespace App\Http\Requests;

use App\Services\WarningService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\App;

class UpdateWarningRequest extends FormRequest
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
        $rules = [
            'key' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
            'parameters' => ['array'],
        ];

        $key = $this->input('key');
        if ($key) {
            $warningService = App::make(WarningService::class);
            $warningDefinition = $warningService->getWarningDefinition($key);

            if ($warningDefinition && isset($warningDefinition['setting-message-parameters'])) {
                $requiredParams = $warningDefinition['setting-message-parameters'];
                foreach ($requiredParams as $param) {
                    $rules["parameters.$param"] = ['required', 'string'];
                }
            }
        }

        return $rules;
    }
}
