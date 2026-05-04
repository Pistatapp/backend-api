<?php

namespace App\Policies;

use App\Models\Irrigation;
use App\Models\User;

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
        $creator = $irrigation->creator;
        $farmAdmin = $irrigation->farm->admins->contains($user);
        $createdAtDiff = $irrigation->created_at->diffInHours(now());
        $endTimeDiff = $irrigation->end_time->diffInHours(now());

        // If user is creator and 24 hours have passed since irrigation creation, return false
        if ($user->is($creator) && $createdAtDiff >= 24) {
            return false;
        }

        // If user is farm admin and 24 hours have passed since irrigation end_time, return false
        if ($farmAdmin && $endTimeDiff >= 24) {
            return false;
        }

        return $user->can('edit-irrigation-program');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Irrigation $irrigation): bool
    {
        $creator = $irrigation->creator;
        $farmAdmin = $irrigation->farm->admins->contains($user);
        $createdAtDiff = $irrigation->created_at->diffInHours(now());
        $endTimeDiff = $irrigation->end_time->diffInHours(now());

        // If user is creator and 24 hours have passed since irrigation creation, return false
        if ($user->is($creator) && $createdAtDiff >= 24) {
            return false;
        }

        // If user is farm admin and 24 hours have passed since irrigation end_time, return false
        if ($farmAdmin && $endTimeDiff >= 24) {
            return false;
        }

        return $user->can('delete-irrigation-program');
    }

    /**
     * Determine whether the user can verify the irrigation.
     */
    public function verify(User $user, Irrigation $irrigation): bool
    {
        // Check if irrigation was verified by admin and if enough time has passed
        if ($irrigation->is_verified_by_admin) {
            $passedTimeSinceLastVerification = $irrigation->updated_at->diffInHours(now());
            if ($passedTimeSinceLastVerification >= 24) {
                return false;
            }
        }

        return $irrigation->farm->admins->contains($user);
    }
}
