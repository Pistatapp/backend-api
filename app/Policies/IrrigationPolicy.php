<?php

namespace App\Policies;

use App\Models\Irrigation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class IrrigationPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasFarm();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Irrigation $irrigation): bool
    {
        return $irrigation->farm->users->contains($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasFarm() && $user->can('define-irrigation-program');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Irrigation $irrigation): bool
    {
        // Cannot update if already verified by admin
        if ($irrigation->is_verified_by_admin) {
            return false;
        }

        // Must have permission to edit irrigation
        if (!$user->can('edit-irrigation-program')) {
            return false;
        }

        // If irrigation is finished, only farm admin can update; if pending, either creator or farm admin can update
        if ($irrigation->status === 'finished') {
            return $irrigation->farm->admins->contains($user);
        }

        return $irrigation->status === 'pending' && (
            $irrigation->creator->is($user) ||
            $irrigation->farm->admins->contains($user)
        );
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Irrigation $irrigation): bool
    {
        // Allow delete if irrigation status is pending AND (user is creator OR farm admin)
        return $irrigation->status === 'pending' && (
            $irrigation->creator->is($user) ||
            $irrigation->farm->admins->contains($user)
        );
    }

    /**
     * Determine whether the user can verify the irrigation.
     */
    public function verify(User $user, Irrigation $irrigation): bool
    {
        // Check if irrigation was verified by admin and if enough time has passed
        if ($irrigation->is_verified_by_admin) {
            $passedTimeSinceLastVerification = $irrigation->updated_at->diffInHours(now());
            if ($passedTimeSinceLastVerification <= 72) {
                return false;
            }
        }

        return $irrigation->farm->admins->contains($user);
    }
}
