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

    /**
     * Determine whether the user can activate another user.
     */
    public function activate(User $user, User $model): bool
    {
        // Root users can activate anyone
        if ($user->hasRole('root')) {
            return true;
        }

        // Admin can only activate users from their own farms
        if ($user->hasAnyRole(['admin', 'super-admin']) && !$model->hasAnyRole(['super-admin', 'root'])) {
            $userFarms = $user->farms()->pluck('farms.id');
            $modelFarms = $model->farms()->pluck('farms.id');

            // Check if they share at least one farm
            return $userFarms->intersect($modelFarms)->isNotEmpty();
        }

        return false;
    }

    /**
     * Determine whether the user can deactivate another user.
     */
    public function deactivate(User $user, User $model): bool
    {
        // Root users can deactivate anyone
        if ($user->hasRole('root')) {
            return true;
        }

        // Admin can only deactivate users from their own farms
        if ($user->hasAnyRole(['admin', 'super-admin']) && !$model->hasAnyRole(['super-admin', 'root'])) {
            $userFarms = $user->farms()->pluck('farms.id');
            $modelFarms = $model->farms()->pluck('farms.id');

            // Check if they share at least one farm
            return $userFarms->intersect($modelFarms)->isNotEmpty();
        }

        return false;
    }
}
