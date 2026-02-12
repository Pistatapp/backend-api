<?php

namespace App\Rules;

use App\Models\Farm;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AllowedFarmAssignment implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = request()->user();

        $farm = Farm::find($value);

        if (!$farm || !$farm->users->contains($user)) {
            $fail(__('You do not have permission to assign access to this farm.'));
        }
    }
}
