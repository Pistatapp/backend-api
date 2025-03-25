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
        // Users can view valves if they have access to the farm
        return true;
    }

    /**
     * Determine whether the user can view the valve.
     */
    public function view(User $user, Valve $valve): bool
    {
        // User can view valve if they have access to the farm
        return $user->farms->contains($valve->pump->farm_id);
    }

    /**
     * Determine whether the user can create valves.
     */
    public function create(User $user): bool
    {
        // Users with farm access can create valves
        return true;
    }

    /**
     * Determine whether the user can update the valve.
     */
    public function update(User $user, Valve $valve): bool
    {
        // User can update valve if they have access to the farm
        return $user->farms->contains($valve->pump->farm_id);
    }

    /**
     * Determine whether the user can delete the valve.
     */
    public function delete(User $user, Valve $valve): bool
    {
        // User can delete valve if they have access to the farm
        return $user->farms->contains($valve->pump->farm_id);
    }

    /**
     * Determine whether the user can restore the valve.
     */
    public function restore(User $user, Valve $valve): bool
    {
        return $user->farms->contains($valve->pump->farm_id);
    }

    /**
     * Determine whether the user can permanently delete the valve.
     */
    public function forceDelete(User $user, Valve $valve): bool
    {
        return $user->farms->contains($valve->pump->farm_id);
    }
}
