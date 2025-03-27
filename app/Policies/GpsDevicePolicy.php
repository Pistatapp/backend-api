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
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, GpsDevice $gpsDevice): bool
    {
        return $user->hasRole('root') || $gpsDevice->user->is($user);
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
        return $user->hasRole('root') && is_null($gpsDevice->vehicle_id);
    }
}
