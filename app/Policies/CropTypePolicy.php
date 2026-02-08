<?php

namespace App\Policies;

use App\Models\CropType;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CropTypePolicy
{
    /**
     * Determine whether the user can view any crop types.
     */
    public function viewAny(?User $user): bool
    {
        return true; // All users can view crop types
    }

    /**
     * Determine whether the user can view the crop type.
     */
    public function view(?User $user, CropType $cropType): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->hasRole('root')) {
            return $cropType->isGlobal();
        }

        return $cropType->isGlobal() || $cropType->isOwnedBy($user->id);
    }

    /**
     * Determine whether the user can create crop types.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['root', 'admin']);
    }

    /**
     * Determine whether the user can update the crop type.
     */
    public function update(User $user, CropType $cropType): bool
    {
        if ($user->hasRole('root')) {
            return $cropType->isGlobal();
        }

        if ($user->hasRole('admin')) {
            return $cropType->isOwnedBy($user->id);
        }

        return false;
    }

    /**
     * Determine whether the user can delete the crop type.
     */
    public function delete(User $user, CropType $cropType): bool
    {
        return false;
    }
}
