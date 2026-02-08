<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class LoadEstimationData implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $requiredStatuses = ['excellent', 'good', 'normal', 'bad'];
        $requiredHeaders = [
            'fruit_cluster_weight',
            'flower_bud_to_fruit_cluster_conversion',
            'estimated_yield_conversion_factor',
        ];

        if (! is_array($value)) {
            $fail('The load estimation data must be an array.');

            return;
        }

        foreach ($requiredStatuses as $status) {
            if (! array_key_exists($status, $value)) {
                $fail("The load estimation data must contain the status: {$status}.");

                return;
            }

            $row = $value[$status];
            if (! is_array($row)) {
                $fail("The load estimation data row for status '{$status}' must be an object.");

                return;
            }

            foreach ($requiredHeaders as $header) {
                if (! array_key_exists($header, $row)) {
                    $fail("The load estimation data must contain the header: {$header} for status '{$status}'.");

                    return;
                }

                $cellValue = $row[$header];
                if ($cellValue !== null && ! is_numeric($cellValue)) {
                    $fail("The value for {$header} in status '{$status}' must be numeric.");
                }
            }
        }

        $extraStatuses = array_diff(array_keys($value), $requiredStatuses);
        if (! empty($extraStatuses)) {
            $fail('The load estimation data must only contain statuses: excellent, good, normal, bad.');
        }
    }
}
