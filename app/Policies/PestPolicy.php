<?php

namespace App\Policies;

use App\Models\Pest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PestPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // All authenticated users can view the list
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Pest $pest): bool
    {
        // Root users can only view global pests
        if ($user->hasRole('root')) {
            return $pest->isGlobal();
        }

        // Other users can view global pests and their own custom pests
        return $pest->isGlobal() || $pest->isOwnedBy($user->id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['root', 'admin']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Pest $pest): bool
    {
        // Root users can only update global pests
        if ($user->hasRole('root')) {
            return $pest->isGlobal();
        }

        // Admin users can only update their own pests
        if ($user->hasRole('admin')) {
            return $pest->isOwnedBy($user->id);
        }

        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Pest $pest): bool
    {
        // Root users can only delete global pests
        if ($user->hasRole('root')) {
            return $pest->isGlobal();
        }

        // Admin users can only delete their own pests
        if ($user->hasRole('admin')) {
            return $pest->isOwnedBy($user->id);
        }

        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Pest $pest): bool
    {
        // Root users can only restore global pests
        if ($user->hasRole('root')) {
            return $pest->isGlobal();
        }

        // Admin users can only restore their own pests
        if ($user->hasRole('admin')) {
            return $pest->isOwnedBy($user->id);
        }

        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Pest $pest): bool
    {
        // Root users can only permanently delete global pests
        if ($user->hasRole('root')) {
            return $pest->isGlobal();
        }

        // Admin users can only permanently delete their own pests
        if ($user->hasRole('admin')) {
            return $pest->isOwnedBy($user->id);
        }

        return false;
    }
}
