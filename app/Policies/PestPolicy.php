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
        return true; // All authenticated users can view individual pests
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('root');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Pest $pest): bool
    {
        return $user->hasRole('root');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Pest $pest): bool
    {
        return $user->hasRole('root');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Pest $pest): bool
    {
        return $user->hasRole('root');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Pest $pest): bool
    {
        return $user->hasRole('root');
    }
}
