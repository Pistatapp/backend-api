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
        return $user->hasRole('admin');
    }

    public function view(User $user, Tractor $tractor): bool
    {
        return $tractor->farm->users->contains($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, Tractor $tractor): bool
    {
        // if user is not admin they cannot update tractor
        if (!$user->hasRole('admin')) {
            return false;
        }

        // if user is not in tractor farm users list they cannot update tractor
        if (!$tractor->farm->users->contains($user)) {
            return false;
        }

        // if tractor has a gps device and driver assigned they cannot be updated
        if ($tractor->gpsDevice()->exists() && $tractor->driver()->exists()) {
            return false;
        }

        return true;
    }

    public function delete(User $user, Tractor $tractor): bool
    {
        // if user is not admin they cannot delete tractor
        if (!$user->hasRole('admin')) {
            return false;
        }

        // if user is not in tractor farm users list they cannot delete tractor
        if (!$tractor->farm->users->contains($user)) {
            return false;
        }

        // if tractor has daily reports or gps device or driver assigned they cannot be deleted
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
        // if user is not admin they cannot make assignments
        if (!$user->hasRole('admin')) {
            return Response::deny('User must be an admin.');
        }

        // if user is not the owner of the gps device they cannot make assignments
        if (!$device->user->is($user)) {
            return Response::deny('User must own the GPS device.');
        }

        // if driver is not in tractor farm users list they cannot make assignments
        if (!$driver->farm->is($tractor->farm)) {
            return Response::deny('Driver must belong to the same farm as the tractor.');
        }

        return Response::allow();
    }
}
