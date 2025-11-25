<?php

namespace App\Policies;

use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Models\User;
use App\Models\Driver;
use Illuminate\Auth\Access\Response;

class TractorPolicy
{

    public function viewAny(User $user): bool
    {
        return $user->hasFarm() && $user->can('view-tractors-and-details');
    }

    public function view(User $user, Tractor $tractor): bool
    {
        return $tractor->farm->users->contains($user) && $user->can('view-tractors-and-details');
    }

    public function create(User $user): bool
    {
        return $user->hasFarm() && $user->can('define-tractor');
    }

    public function update(User $user, Tractor $tractor): bool
    {
        // User must have permission to define tractors
        if (!$user->can('define-tractor')) {
            return false;
        }

        // User must be in tractor farm users list
        if (!$tractor->farm->users->contains($user)) {
            return false;
        }

        // If tractor has a gps device and driver assigned they cannot be updated
        if ($tractor->gpsDevice()->exists() && $tractor->driver()->exists()) {
            return false;
        }

        return true;
    }

    public function delete(User $user, Tractor $tractor): bool
    {
        // User must have permission to define tractors
        if (!$user->can('define-tractor')) {
            return false;
        }

        // User must be in tractor farm users list
        if (!$tractor->farm->users->contains($user)) {
            return false;
        }

        // If tractor has gps device or driver assigned they cannot be deleted
        if ($tractor->gpsDevice()->exists() && $tractor->driver()->exists()) {
            return false;
        }

        return true;
    }

    /**
     * Determine if the user can make assignments to the tractor.
     */
    public function makeAssignments(User $user, Tractor $tractor, GpsDevice $device, Driver $driver): Response
    {
        // User must have permission to assign GPS devices
        if (!$user->can('assign-gps-device')) {
            return Response::deny('User must have permission to assign GPS devices.');
        }

        // User must be in tractor farm users list
        if (!$tractor->farm->users->contains($user)) {
            return Response::deny('User must have access to this farm.');
        }

        // User must own the GPS device or be root
        if (!$user->hasRole('root') && !$device->user->is($user)) {
            return Response::deny('User must own the GPS device.');
        }

        // Driver must belong to the same farm as the tractor
        if (!$driver->farm->is($tractor->farm)) {
            return Response::deny('Driver must belong to the same farm as the tractor.');
        }

        return Response::allow();
    }
}
