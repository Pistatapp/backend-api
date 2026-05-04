<?php

namespace App\Policies;

use App\Models\TractorTask;
use App\Models\User;

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
        $creator = $tractorTask->creator;
        $farmAdmin = $tractorTask->farm->admins->contains($user);
        $createdAtDiff = $tractorTask->created_at->diffInHours(now());

        // If user is creator and 24 hours have passed since tractor task creation, return false
        if ($user->is($creator) && $createdAtDiff >= 24) {
            return false;
        }

        // If user is farm admin and 48 hours have passed since tractor task creation, return false
        if ($farmAdmin && $createdAtDiff >= 48) {
            return false;
        }

        return $user->can('assign-tractor-task');
    }

    public function delete(User $user, TractorTask $tractorTask): bool
    {
        $creator = $tractorTask->creator;
        $farmAdmin = $tractorTask->farm->admins->contains($user);
        $createdAtDiff = $tractorTask->created_at->diffInHours(now());
        // If user is creator and 24 hours have passed since tractor task creation, return false
        if ($user->is($creator) && $createdAtDiff >= 24) {
            return false;
        }

        // If user is farm admin and 48 hours have passed since tractor task creation, return false
        if ($farmAdmin && $createdAtDiff >= 48) {
            return false;
        }

        return $user->can('assign-tractor-task');
    }
}
