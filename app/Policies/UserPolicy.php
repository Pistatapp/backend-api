<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('manage-users');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        return $model->creator && $model->creator->is($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('manage-users');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        return $model->creator && $model->creator->is($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        return $model->creator && $model->creator->is($user);
    }

    /**
     * Determine whether the user can attach a user to a farm.
     */
    public function attach(User $user, User $model): bool
    {
        return $user->hasAnyRole(['admin', 'super-admin']) && !$model->hasAnyRole(['super-admin', 'root']);
    }

    /**
     * Determine whether the user can detach a user from a farm.
     */
    public function detach(User $user, User $model): bool
    {
        return $user->hasAnyRole(['admin', 'super-admin']) && !$model->hasAnyRole(['super-admin', 'root']);
    }
}
