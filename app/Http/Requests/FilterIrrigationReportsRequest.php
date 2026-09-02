<?php

namespace App\Http\Requests;

use App\Models\Farm;
use App\Models\Field;
use App\Models\Plot;
use App\Models\Valve;
use Illuminate\Foundation\Http\FormRequest;

class FilterIrrigationReportsRequest extends FormRequest
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
        return [
            // Explicit hierarchical scope. At least one of these arrays is
            // required; plot_ids and valves remain supported for old clients.
            'field_ids' => 'nullable|array',
            'field_ids.*' => 'integer|exists:fields,id',
            'plot_ids' => 'nullable|array',
            'plot_ids.*' => 'integer|exists:plots,id',
            'valve_ids' => 'nullable|array',
            'valve_ids.*' => 'integer|exists:valves,id',
            'valves' => 'nullable|array',
            'valves.*' => 'integer|exists:valves,id',
            'labour_id' => 'nullable|integer|exists:labours,id',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date'
        ];
    }

    /**
     * Require a non-empty reporting scope while accepting either the new
     * valve_ids key or the legacy valves alias.
     */
    protected function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $scopeKeys = ['field_ids', 'plot_ids', 'valve_ids', 'valves'];
            $hasScope = collect($scopeKeys)->contains(function (string $key): bool {
                return is_array($this->input($key)) && count($this->input($key)) > 0;
            });

            if (! $hasScope) {
                $validator->errors()->add(
                    'plot_ids',
                    'At least one field, plot, or valve must be selected.'
                );
            }

            $farm = $this->route('farm');
            $farmId = $farm instanceof Farm ? $farm->id : $farm;
            if (! is_numeric($farmId)) {
                return;
            }

            $fieldIds = $this->input('field_ids');
            if (is_array($fieldIds) && $fieldIds !== [] && Field::whereIn('id', $fieldIds)->where('farm_id', $farmId)->count() !== count(array_unique($fieldIds))) {
                $validator->errors()->add('field_ids', 'All selected fields must belong to this farm.');
            }

            $plotIds = $this->input('plot_ids');
            if (is_array($plotIds) && $plotIds !== [] && Plot::whereIn('id', $plotIds)->whereHas('field', fn ($query) => $query->where('farm_id', $farmId))->count() !== count(array_unique($plotIds))) {
                $validator->errors()->add('plot_ids', 'All selected plots must belong to this farm.');
            }

            $valveIds = $this->input('valve_ids');
            if ($valveIds === null) {
                $valveIds = $this->input('valves');
            }
            if (is_array($valveIds) && $valveIds !== [] && Valve::whereIn('id', $valveIds)->whereHas('plot.field', fn ($query) => $query->where('farm_id', $farmId))->count() !== count(array_unique($valveIds))) {
                $validator->errors()->add('valve_ids', 'All selected valves must belong to this farm.');
            }
        });
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        // Explicit nulls are valid for optional client scope fields. Normalize
        // them so validation and downstream services never call array helpers
        // on null.
        foreach (['field_ids', 'plot_ids', 'valve_ids', 'valves'] as $key) {
            if ($this->has($key) && $this->input($key) === null) {
                $this->merge([$key => []]);
            }
        }

        $dates = ['from_date' => 'from_date', 'to_date' => 'to_date'];

        foreach ($dates as $input => $output) {
            if ($this->$input) {
                $this->merge([
                    $output => jalali_to_carbon($this->$input),
                ]);
            }
        }
    }
}
