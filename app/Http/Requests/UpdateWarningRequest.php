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
            'parameters' => ['required', 'array'],
            'type' => ['sometimes', 'string', 'in:one-time,schedule-based,condition-based'],
        ];

        $key = $this->input('key');
        if ($key) {
            $warningService = App::make(WarningService::class);
            $warningDefinition = $warningService->getWarningDefinition($key);

            if ($warningDefinition && isset($warningDefinition['setting-message-parameters'])) {
                foreach ($warningDefinition['setting-message-parameters'] as $param) {
                    $rules["parameters.$param"] = ['required', 'string'];
                }
            }
        }

        return $rules;
    }

    /**
     * Prepare the data for validation.
     **/
    protected function prepareForValidation()
    {
        if (isset($this->parameters['start_date']) && isset($this->parameters['end_date']) || isset($this->parameters['date'])) {
            $this->merge([
                'parameters' => array_merge($this->parameters, [
                    'start_date' => jalali_to_carbon($this->parameters['start_date']) ?? null,
                    'end_date' => jalali_to_carbon($this->parameters['end_date']) ?? null,
                    'date' => jalali_to_carbon($this->parameters['date']) ?? null,
                ])
            ]);
        }
    }
}
