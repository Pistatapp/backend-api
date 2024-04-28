<?php

namespace App\Policies;

use App\Models\Irrigation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class IrrigationPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Irrigation $irrigation): bool
    {
        return $irrigation->creator->is($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Irrigation $irrigation): bool
    {
        return $irrigation->creator->is($user);
    }
}
