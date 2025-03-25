<?php

namespace App\Policies;

use App\Models\Pump;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PumpPolicy
{
    /**
     * Determine whether the user can view any models.
     * User can view pumps if they have access to the farm
     */
    public function viewAny(User $user): bool
    {
        return true; // Will be filtered by farm access
    }

    /**
     * Determine whether the user can view the model.
     * User can view a pump if they have access to its farm
     */
    public function view(User $user, Pump $pump): bool
    {
        return $user->can('view', $pump->farm);
    }

    /**
     * Determine whether the user can create models.
     * User can create pumps if they can manage the farm
     */
    public function create(User $user): bool
    {
        return true; // Will be filtered by farm access in controller
    }

    /**
     * Determine whether the user can update the model.
     * User can update a pump if they can manage its farm
     */
    public function update(User $user, Pump $pump): bool
    {
        return $user->can('update', $pump->farm);
    }

    /**
     * Determine whether the user can delete the model.
     * User can delete a pump if they can manage its farm
     */
    public function delete(User $user, Pump $pump): bool
    {
        return $user->can('delete', $pump->farm);
    }

    /**
     * Determine whether the user can restore the model.
     * User can restore a pump if they can manage its farm
     */
    public function restore(User $user, Pump $pump): bool
    {
        return $user->can('update', $pump->farm);
    }

    /**
     * Determine whether the user can permanently delete the model.
     * User can force delete a pump if they can manage its farm
     */
    public function forceDelete(User $user, Pump $pump): bool
    {
        return $user->can('delete', $pump->farm);
    }
}
