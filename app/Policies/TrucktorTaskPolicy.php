<?php

namespace App\Policies;

use App\Models\TrucktorTask;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TrucktorTaskPolicy
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
    public function view(User $user, TrucktorTask $trucktorTask): bool
    {
        return $trucktorTask->creator->is($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, TrucktorTask $trucktorTask): bool
    {
        return $trucktorTask->creator->is($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, TrucktorTask $trucktorTask): bool
    {
        return $trucktorTask->creator->is($user) && $trucktorTask->status === 'pending';
    }
}
