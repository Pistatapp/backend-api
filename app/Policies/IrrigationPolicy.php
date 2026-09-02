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
        $lifecycle = app(\App\Services\IrrigationLifecycleService::class);
        $farmAdmin = $irrigation->farm->admins->contains($user);

        if ($farmAdmin) {
            return $lifecycle->canAdminEdit($irrigation) && $user->can('edit-irrigation-program');
        }

        return $user->is($irrigation->creator)
            && $lifecycle->canOperatorEdit($irrigation)
            && $user->can('edit-irrigation-program');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Irrigation $irrigation): bool
    {
        $lifecycle = app(\App\Services\IrrigationLifecycleService::class);
        $farmAdmin = $irrigation->farm->admins->contains($user);

        if ($farmAdmin) {
            return $lifecycle->canAdminEdit($irrigation) && $user->can('delete-irrigation-program');
        }

        return $user->is($irrigation->creator)
            && $lifecycle->canOperatorEdit($irrigation)
            && $user->can('delete-irrigation-program');
    }

    /**
     * Determine whether the user can verify the irrigation.
     */
    public function verify(User $user, Irrigation $irrigation): bool
    {
        $lifecycle = app(\App\Services\IrrigationLifecycleService::class);
        return $irrigation->farm->admins->contains($user)
            && $lifecycle->canAdminConfirm($irrigation);
    }

    public function confirmOperator(User $user, Irrigation $irrigation): bool
    {
        $lifecycle = app(\App\Services\IrrigationLifecycleService::class);
        return $user->is($irrigation->creator)
            && $lifecycle->canOperatorConfirm($irrigation);
    }
}
