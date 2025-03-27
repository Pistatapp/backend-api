<?php

namespace App\Policies;

use App\Models\CropType;
use App\Models\User;

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
        return true; // All users can view individual crop types
    }

    /**
     * Determine whether the user can create crop types.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('root');
    }

    /**
     * Determine whether the user can update the crop type.
     */
    public function update(User $user, CropType $cropType): bool
    {
        return $user->hasRole('root');
    }

    /**
     * Determine whether the user can delete the crop type.
     */
    public function delete(User $user, CropType $cropType): bool
    {
        return $user->hasRole('root') && $cropType->fields()->count() === 0;
    }
}
