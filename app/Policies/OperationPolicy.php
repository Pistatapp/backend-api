<?php

namespace App\Policies;

use App\Models\Operation;
use App\Models\User;

class OperationPolicy
{
    /**
     * Determine whether the user can view any operations.
     *
     * @param  \App\Models\User  $user
     * @return mixed
     */
    public function viewAny(User $user)
    {
        return $user->farms()->exists();
    }

    /**
     * Determine whether the user can view the operation.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Operation  $operation
     * @return mixed
     */
    public function view(User $user, Operation $operation)
    {
        return $operation->farm->users->contains($user);
    }

    /**
     * Determine whether the user can create operations.
     *
     * @param  \App\Models\User  $user
     * @return mixed
     */
    public function create(User $user)
    {
        return $user->farms()->exists();
    }

    /**
     * Determine whether the user can update the operation.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Operation  $operation
     * @return mixed
     */
    public function update(User $user, Operation $operation)
    {
        return $operation->farm->users->contains($user);
    }

    /**
     * Determine whether the user can delete the operation.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Operation  $operation
     * @return mixed
     */
    public function delete(User $user, Operation $operation)
    {
        return $operation->farm->users->contains($user);
    }
}
