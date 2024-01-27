<?php

namespace App\Policies;

use App\Models\GpsDevice;
use App\Models\Trucktor;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TrucktorPolicy
{
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
    public function update(User $user, Trucktor $trucktor): bool
    {
        return $trucktor->farm->user->is($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Trucktor $trucktor): bool
    {
        return $trucktor->farm->user->is($user);
    }

    /**
     * Detemine whether the user can assign device to the trucktor.
     */
    public function assignDevice(User $user, Trucktor $trucktor, GpsDevice $gpsDevice): bool
    {
        return $gpsDevice->user->is($user) && $trucktor->gpsDevice()->doesntExist();
    }
}
