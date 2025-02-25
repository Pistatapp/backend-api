<?php

namespace App\Policies;

use App\Models\TractorReport;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TractorReportPolicy
{
    // ...existing code...

    public function view(User $user, TractorReport $tractorReport): bool
    {
        return $tractorReport->user->is($user);
    }

    // ...existing code...

    public function update(User $user, TractorReport $tractorReport): bool
    {
        return $tractorReport->user->is($user);
    }

    // ...existing code...

    public function delete(User $user, TractorReport $tractorReport): bool
    {
        return $tractorReport->user->is($user);
    }
}
