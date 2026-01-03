<?php

namespace App\Policies;

use App\Models\DeviceConnectionRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DeviceConnectionRequestPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('root');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, DeviceConnectionRequest $deviceConnectionRequest): bool
    {
        return $user->hasRole('root');
    }

    /**
     * Determine whether the user can create models.
     * Mobile app creates requests without authentication.
     */
    public function create(?User $user): bool
    {
        return true; // Mobile app doesn't require auth
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, DeviceConnectionRequest $deviceConnectionRequest): bool
    {
        return $user->hasRole('root');
    }

    /**
     * Determine whether the user can approve the request.
     */
    public function approve(User $user, DeviceConnectionRequest $deviceConnectionRequest): bool
    {
        return $user->hasRole('root');
    }

    /**
     * Determine whether the user can reject the request.
     */
    public function reject(User $user, DeviceConnectionRequest $deviceConnectionRequest): bool
    {
        return $user->hasRole('root');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, DeviceConnectionRequest $deviceConnectionRequest): bool
    {
        return $user->hasRole('root');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, DeviceConnectionRequest $deviceConnectionRequest): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, DeviceConnectionRequest $deviceConnectionRequest): bool
    {
        return false;
    }
}
