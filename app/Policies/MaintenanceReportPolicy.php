<?php

namespace App\Policies;

use App\Models\MaintenanceReport;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MaintenanceReportPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, MaintenanceReport $maintenanceReport): bool
    {
        return $user->id === $maintenanceReport->created_by;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, MaintenanceReport $maintenanceReport): bool
    {
        return $user->id === $maintenanceReport->created_by;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, MaintenanceReport $maintenanceReport): bool
    {
        return $user->id === $maintenanceReport->created_by;
    }
}
