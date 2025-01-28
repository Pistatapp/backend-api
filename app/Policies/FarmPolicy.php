<?php

namespace App\Policies;

use App\Models\Farm;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class FarmPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view-farms');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Farm $farm): bool
    {
        return $farm->users->contains($user) && $user->can('view-farm-details');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create-farm');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Farm $farm): bool
    {
        return $farm->users->contains($user) && $user->can('edit-farm');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Farm $farm): bool
    {
        return $farm->users->contains($user) && $user->can('delete-farm');
    }

    /**
     * Determine whether the user can set the farm as working environment.
     */
    public function setWorkingEnvironment(User $user, Farm $farm): bool
    {
        return $farm->users->contains($user);
    }
}
