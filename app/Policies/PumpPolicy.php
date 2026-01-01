<?php

namespace App\Policies;

use App\Models\Pump;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PumpPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasFarm() && $user->can('view-pump-and-valve-info');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Pump $pump): bool
    {
        return $pump->farm->users->contains($user) && $user->can('view-pump-and-valve-info');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasFarm() && $user->can('draw-pump-and-valve');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Pump $pump): bool
    {
        return $pump->farm->users->contains($user) && $user->can('draw-pump-and-valve');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Pump $pump): bool
    {
        return $pump->farm->users->contains($user) && $user->can('draw-pump-and-valve');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Pump $pump): bool
    {
        return $pump->farm->users->contains($user) && $user->can('draw-pump-and-valve');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Pump $pump): bool
    {
        return $pump->farm->users->contains($user) && $user->can('draw-pump-and-valve');
    }
}
