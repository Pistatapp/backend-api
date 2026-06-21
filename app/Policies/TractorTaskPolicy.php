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
        return $this->canModify($user, $tractorTask);
    }

    public function delete(User $user, TractorTask $tractorTask): bool
    {
        return $this->canModify($user, $tractorTask);
    }

    private function canModify(User $user, TractorTask $tractorTask): bool
    {
        $elapsedSinceCompletion = $this->elapsedHoursSinceCompletion($tractorTask);

        if ($user->is($tractorTask->creator) && $elapsedSinceCompletion >= 24) {
            return false;
        }

        if ($tractorTask->farm->admins->contains($user) && $elapsedSinceCompletion >= 48) {
            return false;
        }

        return $user->can('assign-tractor-task');
    }

    private function elapsedHoursSinceCompletion(TractorTask $tractorTask): int
    {
        $taskEndDateTime = $tractorTask->getEndDateTime();

        if ($taskEndDateTime->isFuture()) {
            return 0;
        }

        return $taskEndDateTime->diffInHours(now());
    }
}
