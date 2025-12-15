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

            if (!$warningDefinition) {
                // Add custom validation for invalid key
                $rules['key'] = array_merge($rules['key'], [
                    function ($attribute, $value, $fail) {
                        $fail('The selected warning key is invalid.');
                    }
                ]);
                return $rules;
            }

            // Get the setting-message-parameters for this specific warning
            $settingParameters = $warningDefinition['setting-message-parameters'] ?? [];

            // If this warning has no setting parameters, ensure parameters array is empty
            if (empty($settingParameters)) {
                $rules['parameters'] = array_merge($rules['parameters'], [
                    function ($attribute, $value, $fail) {
                        if (!empty($value)) {
                            $fail('This warning type does not require any parameters.');
                        }
                    }
                ]);
            } else {
                // Validate that only allowed parameters are provided
                $rules['parameters'] = array_merge($rules['parameters'], [
                    function ($attribute, $value, $fail) use ($settingParameters) {
                        $providedParams = array_keys($value);
                        $allowedParams = $settingParameters;
                        $extraParams = array_diff($providedParams, $allowedParams);

                        if (!empty($extraParams)) {
                            $fail('The parameters field contains invalid parameters: ' . implode(', ', $extraParams));
                        }
                    }
                ]);

                // Validate each required parameter with appropriate type based on the warning definition
                foreach ($settingParameters as $param) {
                    $paramRules = $this->getParameterValidationRules($param);
                    $rules["parameters.$param"] = array_merge(['required'], $paramRules);
                }
            }
        }

        return $rules;
    }

    /**
     * Get validation rules for a specific parameter based on its name.
     *
     * @param string $param
     * @return array
     */
    protected function getParameterValidationRules(string $param): array
    {
        // Date parameters - validate Jalali date format
        if (in_array($param, ['start_date', 'end_date', 'date'])) {
            return [
                'string',
                function ($attribute, $value, $fail) {
                    if (!is_jalali_date($value)) {
                        $fail('The ' . $attribute . ' must be a valid Jalali date in format YYYY/MM/DD (e.g., 1403/01/15).');
                    }
                }
            ];
        }

        // Time-based integer parameters
        if (in_array($param, ['hours', 'days'])) {
            return ['integer', 'min:1'];
        }

        // Numeric parameters
        if ($param === 'degree_days') {
            return ['numeric', 'min:0'];
        }

        // String parameters
        if (in_array($param, ['pest', 'crop_type'])) {
            return ['string', 'max:255'];
        }

        // Default: string for any other parameter
        return ['string'];
    }

    /**
     * Prepare the data for validation.
     **/
    protected function prepareForValidation()
    {
        if (isset($this->parameters)) {
            $convertedParams = [];

            // Convert Jalali dates to Carbon instances
            $dateParams = ['start_date', 'end_date', 'date'];
            foreach ($dateParams as $dateParam) {
                if (isset($this->parameters[$dateParam])) {
                    try {
                        $convertedParams[$dateParam] = jalali_to_carbon($this->parameters[$dateParam]);
                    } catch (\InvalidArgumentException $e) {
                        // Keep original value if conversion fails - validation will catch it
                        $convertedParams[$dateParam] = $this->parameters[$dateParam];
                    }
                }
            }

            if (!empty($convertedParams)) {
                $this->merge([
                    'parameters' => array_merge($this->parameters, $convertedParams)
                ]);
            }
        }
    }

    /**
     * Get custom validation messages.
     *
     * @return array
     */
    public function messages(): array
    {
        $key = $this->input('key');
        $messages = [];

        if ($key) {
            $warningService = App::make(WarningService::class);
            $warningDefinition = $warningService->getWarningDefinition($key);

            if ($warningDefinition) {
                $settingParameters = $warningDefinition['setting-message-parameters'] ?? [];

                foreach ($settingParameters as $param) {
                    $messages["parameters.$param.required"] = "The parameter '{$param}' is required for this warning type.";

                    if (in_array($param, ['hours', 'days'])) {
                        $messages["parameters.$param.integer"] = "The parameter '{$param}' must be an integer.";
                        $messages["parameters.$param.min"] = "The parameter '{$param}' must be at least 1.";
                    }

                    if ($param === 'degree_days') {
                        $messages["parameters.$param.numeric"] = "The parameter '{$param}' must be a number.";
                        $messages["parameters.$param.min"] = "The parameter '{$param}' must be at least 0.";
                    }

                    if (in_array($param, ['start_date', 'end_date', 'date'])) {
                        $messages["parameters.$param.string"] = "The parameter '{$param}' must be a string.";
                    }
                }
            }
        }

        return $messages;
    }
}
