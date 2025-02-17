<?php

namespace App\Policies;

use App\Models\tractorTask;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class tractorTaskPolicy
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
    public function view(User $user, tractorTask $tractorTask): bool
    {
        return $tractorTask->creator->is($user);
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
    public function update(User $user, tractorTask $tractorTask): bool
    {
        return $tractorTask->creator->is($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, tractorTask $tractorTask): bool
    {
        return $tractorTask->creator->is($user) && $tractorTask->status === 'pending';
    }
}
