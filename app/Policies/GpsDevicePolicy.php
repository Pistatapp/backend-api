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
        return $user->is_admin;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, GpsDevice $gpsDevice): bool
    {
        return $user->is_admin || $gpsDevice->user->is($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, GpsDevice $gpsDevice): bool
    {
        return $user->is_admin && is_null($gpsDevice->vehicle_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, GpsDevice $gpsDevice): bool
    {
        return $user->is_admin || is_null($gpsDevice->vehicle_id);
    }
}
