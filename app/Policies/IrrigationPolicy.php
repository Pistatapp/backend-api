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
     * Determine whether the user can view the model.
     */
    public function view(User $user, Irrigation $irrigation): bool
    {
        return $irrigation->creator->is($user)
            || $irrigation->farm->users->contains($user);
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
        // Allow update if irrigation status is pending AND (user is creator OR farm admin)
        return $irrigation->status === 'pending' && (
            $irrigation->creator->is($user) ||
            $irrigation->farm->admins->contains($user)
        );
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Irrigation $irrigation): bool
    {
        // Allow delete if irrigation status is pending AND (user is creator OR farm admin)
        return $irrigation->status === 'pending' && (
            $irrigation->creator->is($user) ||
            $irrigation->farm->admins->contains($user)
        );
    }
}
