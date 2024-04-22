<?php

namespace App\Policies;

use App\Models\Operation;
use App\Models\User;

class OperationPolicy
{
    /**
     * Determine whether the user can update the operation.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Operation  $operation
     * @return mixed
     */
    public function update(User $user, Operation $operation)
    {
        return $operation->user->is($user);
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
        return $operation->user->is($user);
    }
}
