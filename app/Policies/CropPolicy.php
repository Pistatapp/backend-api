<?php

namespace App\Policies;

use App\Models\Crop;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Log;

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
        return true; // All authenticated users can view individual crops
    }

    /**
     * Determine whether the user can create crops.
     */
    public function create(User $user): bool
    {
        return (bool) $user->hasRole('root');
    }

    /**
     * Determine whether the user can update the crop.
     */
    public function update(User $user, Crop $crop): bool
    {
        return (bool) $user->hasRole('root');
    }

    /**
     * Determine whether the user can delete the crop.
     */
    public function delete(User $user, Crop $crop): bool
    {
        return $user->hasRole('root') && $crop->farms()->count() == 0;
    }
}
