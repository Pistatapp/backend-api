<?php

namespace App\Policies;

use App\Models\TractorReport;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TractorReportPolicy
{
    /**
     * Determine whether the user can view any tractor reports.
     *
     * @param  \App\Models\User  $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the tractor report.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\TractorReport  $tractorReport
     * @return bool
     */
    public function view(User $user, TractorReport $tractorReport): bool
    {
        return $tractorReport->creator->is($user);
    }

    /**
     * Determine whether the user can create tractor reports.
     *
     * @param  \App\Models\User  $user
     * @return bool
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the tractor report.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\TractorReport  $tractorReport
     * @return bool
     */
    public function update(User $user, TractorReport $tractorReport): bool
    {
        return $tractorReport->creator->is($user);
    }

    /**
     * Determine whether the user can delete the tractor report.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\TractorReport  $tractorReport
     * @return bool
     */
    public function delete(User $user, TractorReport $tractorReport): bool
    {
        return $tractorReport->creator->is($user);
    }
}
