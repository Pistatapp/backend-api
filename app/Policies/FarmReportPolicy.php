<?php

namespace App\Policies;

use App\Models\FarmReport;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class FarmReportPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasFarm() && $user->can('view-feature-reports');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, FarmReport $farmReport): bool
    {
        return $farmReport->farm->users->contains($user) && $user->can('view-feature-reports');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasFarm() && $user->can('submit-feature-report');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, FarmReport $farmReport): bool
    {
        return ($farmReport->creator->is($user) || $farmReport->farm->admins->contains($user))
            && $user->can('submit-feature-report');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, FarmReport $farmReport): bool
    {
        return $farmReport->creator->is($user) && $user->can('submit-feature-report');
    }

    /**
     * Determine whether the user can verify the model.
     */
    public function verify(User $user, FarmReport $farmReport): bool
    {
        return $farmReport->farm->users->contains($user)
            && $user->can('approve-reject-user-reports');
    }
}
