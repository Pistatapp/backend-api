<?php

namespace App\Policies;

use App\Models\Plot;
use App\Models\User;

class PlotPolicy
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
    public function view(User $user, Plot $plot): bool
    {
        return $plot->field->farm->users->contains($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasFarm() && $user->can('draw-field-block-row-tree');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Plot $plot): bool
    {
        return $plot->field->farm->users->contains($user) && $user->can('draw-field-block-row-tree');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Plot $plot): bool
    {
        return $plot->field->farm->users->contains($user) && $user->can('draw-field-block-row-tree');
    }
}

