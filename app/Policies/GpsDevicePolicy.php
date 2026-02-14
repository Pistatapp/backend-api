<?php

namespace App\Policies;

use App\Models\GpsDevice;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class GpsDevicePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Root can see all, Orchard admin can see devices for their farms
        return $user->hasRole('root') || $user->hasRole('admin');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, GpsDevice $gpsDevice): bool
    {
        if ($user->hasRole('root')) {
            return true;
        }

        // Orchard admin can only see devices whose owner belongs to their farms
        if ($user->hasRole('admin')) {
            return $user->farms()->whereHas('users', fn ($q) => $q->where('users.id', $gpsDevice->user_id))->exists();
        }

        return false;
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
    public function update(User $user, GpsDevice $gpsDevice): bool
    {
        return $user->hasRole('root');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, GpsDevice $gpsDevice): bool
    {
        return $user->hasRole('root');
    }
}
