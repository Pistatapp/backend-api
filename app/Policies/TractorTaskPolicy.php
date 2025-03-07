<?php

namespace App\Policies;

use App\Models\TractorTask;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TractorTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-defined-tractor-tasks');
    }

    public function view(User $user, TractorTask $tractorTask): bool
    {
        return $tractorTask->creator->is($user)
            || $user->can('view-defined-tractor-tasks');
    }

    public function create(User $user): bool
    {
        return $user->can('assign-tractor-task');
    }

    public function update(User $user, TractorTask $tractorTask): bool
    {
        return $tractorTask->creator->is($user);
    }

    public function delete(User $user, TractorTask $tractorTask): bool
    {
        return $tractorTask->creator->is($user) && $tractorTask->status === 'pending';
    }
}
