<?php

namespace App\Policies;

use App\Models\Crop;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CropPolicy
{
    /**
     * Determine whether the user can view any crops.
     */
    public function viewAny(?User $user): bool
    {
        return true; // All authenticated users can view crops
    }

    /**
     * Determine whether the user can view the crop.
     */
    public function view(?User $user, Crop $crop): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->hasRole('root')) {
            return $crop->isGlobal();
        }

        return $crop->isGlobal() || $crop->isOwnedBy($user->id);
    }

    /**
     * Determine whether the user can create crops.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['root', 'admin']);
    }

    /**
     * Determine whether the user can update the crop.
     */
    public function update(User $user, Crop $crop): bool
    {
        if ($user->hasRole('root')) {
            return $crop->isGlobal();
        }

        if ($user->hasRole('admin')) {
            return $crop->isOwnedBy($user->id);
        }

        return false;
    }

    /**
     * Determine whether the user can delete the crop.
     */
    public function delete(User $user, Crop $crop): bool
    {
        return false;
    }
}
