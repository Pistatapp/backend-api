<?php

namespace App\Policies;

use App\Models\Row;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class RowPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Row $row): bool
    {
        return $row->field->farm->users->contains($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasFarm();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Row $row): bool
    {
        return $row->field->farm->users->contains($user);
    }
}
