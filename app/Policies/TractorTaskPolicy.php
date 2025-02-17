<?php

namespace App\Policies;

use App\Models\TractorTask;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TractorTaskPolicy
{
    // ...existing code...

    public function view(User $user, TractorTask $tractorTask): bool
    {
        return $tractorTask->creator->is($user);
    }

    // ...existing code...

    public function update(User $user, TractorTask $tractorTask): bool
    {
        return $tractorTask->creator->is($user);
    }

    // ...existing code...

    public function delete(User $user, TractorTask $tractorTask): bool
    {
        return $tractorTask->creator->is($user) && $tractorTask->status === 'pending';
    }
}
