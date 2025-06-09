<?php

namespace App\Policies;

use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Models\User;
use App\Models\Driver;
use Illuminate\Auth\Access\Response;

class TractorPolicy
{
    public function update(User $user, Tractor $tractor): bool
    {
        return $tractor->farm->users->contains($user) && $user->hasRole('admin');
    }

    public function delete(User $user, Tractor $tractor): bool
    {
        return $tractor->farm->users->contains($user) && $user->hasRole('admin');
    }

    /**
     * Determine if the user can make assignments to the tractor.
     */
    public function makeAssignments(User $user, Tractor $tractor, GpsDevice $device, Driver $driver): Response
    {
        if (!$user->hasRole('admin')) {
            return Response::deny('User must be an admin.');
        }

        if (!$device->user->is($user)) {
            return Response::deny('User must own the GPS device.');
        }

        if (!$driver->farm->is($tractor->farm)) {
            return Response::deny('Driver must belong to the same farm as the tractor.');
        }

        return Response::allow();
    }

    public function assignDevice(User $user, Tractor $tractor, GpsDevice $gpsDevice): bool
    {
        return $gpsDevice->user->is($user) && $tractor->gpsDevice()->doesntExist() && $user->hasRole('admin');
    }

    public function unassignDevice(User $user, Tractor $tractor, GpsDevice $gpsDevice): bool
    {
        return $tractor->gpsDevice->is($gpsDevice) && $tractor->farm->users->contains($user) && $user->hasRole('admin');
    }

    public function assignDriver(User $user, Tractor $tractor, Driver $driver): bool
    {
        return $driver->farm->users->contains($user)
            && $user->hasRole('admin')
            && $tractor->driver()->doesntExist()
            && $driver->tractor()->doesntExist()
            && $driver->farm->users->contains($user);
    }

    public function unassignDriver(User $user, Tractor $tractor, Driver $driver): bool
    {
        return $tractor->driver->is($driver)
        && $tractor->farm->users->contains($user)
        && $user->hasRole('admin');
    }

    public function view(User $user, Tractor $tractor): bool
    {
        return $tractor->farm->users->contains($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }
}
