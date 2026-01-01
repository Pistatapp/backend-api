<?php

namespace App\Policies;

use App\Models\Labour;
use App\Models\User;

class LabourPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasFarm();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Labour $labour): bool
    {
        return $labour->farm->users->contains($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasFarm() && $user->can('define-worker');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Labour $labour): bool
    {
        return $labour->farm->users->contains($user) && $user->can('edit-worker');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Labour $labour): bool
    {
        return $labour->farm->users->contains($user) && $user->can('edit-worker');
    }
}

