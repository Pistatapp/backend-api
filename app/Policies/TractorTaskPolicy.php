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
        $elapsedSinceCompletion = $this->elapsedHoursSinceCompletion($tractorTask);

        // If user is creator and 24 hours have passed since tractor task completion, return false
        if ($user->is($creator) && $elapsedSinceCompletion >= 24) {
            return false;
        }

        // If user is farm admin and 48 hours have passed since tractor task completion, return false
        if ($farmAdmin && $elapsedSinceCompletion >= 48) {
            return false;
        }

        return $user->can('assign-tractor-task');
    }

    public function delete(User $user, TractorTask $tractorTask): bool
    {
        $creator = $tractorTask->creator;
        $farmAdmin = $tractorTask->farm->admins->contains($user);
        $elapsedSinceCompletion = $this->elapsedHoursSinceCompletion($tractorTask);
        // If user is creator and 24 hours have passed since tractor task completion, return false
        if ($user->is($creator) && $elapsedSinceCompletion >= 24) {
            return false;
        }

        // If user is farm admin and 48 hours have passed since tractor task completion, return false
        if ($farmAdmin && $elapsedSinceCompletion >= 48) {
            return false;
        }

        return $user->can('assign-tractor-task');
    }

    private function elapsedHoursSinceCompletion(TractorTask $tractorTask): int
    {
        $taskEndDateTime = $tractorTask->date->copy()
            ->setTimeFromTimeString($tractorTask->end_time->format('H:i:s'));

        if ($taskEndDateTime->isFuture()) {
            return 0;
        }

        return $taskEndDateTime->diffInHours(now());
    }
}
