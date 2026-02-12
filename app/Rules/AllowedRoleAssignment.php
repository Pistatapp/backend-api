<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AllowedRoleAssignment implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = request()->user();

        $allowedRoles = [];

        if ($user->hasRole('root')) {
            $allowedRoles = ['super-admin', 'admin'];
        } elseif ($user->hasRole('super-admin')) {
            $allowedRoles = ['admin', 'consultant', 'inspector'];
        } elseif ($user->hasRole('admin')) {
            $allowedRoles = ['operator', 'viewer', 'consultant', 'labour', 'employee'];
        }

        if (!in_array($value, $allowedRoles)) {
            $fail(__('You do not have permission to assign this role.'));
        }
    }
}
