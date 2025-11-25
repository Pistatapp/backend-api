<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Valve;
use Illuminate\Auth\Access\HandlesAuthorization;

class ValvePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any valves.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasFarm();
    }

    /**
     * Determine whether the user can view the valve.
     */
    public function view(User $user, Valve $valve): bool
    {
        // Valves belong to plots, which belong to fields, which belong to farms
        return $valve->plot->field->farm->users->contains($user);
    }

    /**
     * Determine whether the user can create valves.
     */
    public function create(User $user): bool
    {
        return $user->hasFarm() && $user->can('draw-pump-and-valve');
    }

    /**
     * Determine whether the user can update the valve.
     */
    public function update(User $user, Valve $valve): bool
    {
        return $valve->plot->field->farm->users->contains($user) && $user->can('draw-pump-and-valve');
    }

    /**
     * Determine whether the user can delete the valve.
     */
    public function delete(User $user, Valve $valve): bool
    {
        return $valve->plot->field->farm->users->contains($user) && $user->can('draw-pump-and-valve');
    }

    /**
     * Determine whether the user can restore the valve.
     */
    public function restore(User $user, Valve $valve): bool
    {
        return $valve->plot->field->farm->users->contains($user) && $user->can('draw-pump-and-valve');
    }

    /**
     * Determine whether the user can permanently delete the valve.
     */
    public function forceDelete(User $user, Valve $valve): bool
    {
        return $valve->plot->field->farm->users->contains($user) && $user->can('draw-pump-and-valve');
    }
}
