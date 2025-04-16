<?php

namespace App\Policies;

use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TractorPolicy
{
    // ...existing code...

    public function update(User $user, Tractor $tractor): bool
    {
        return $tractor->farm->users->contains($user) && $user->hasRole('admin');
    }

    // ...existing code...

    public function delete(User $user, Tractor $tractor): bool
    {
        return $tractor->farm->users->contains($user) && $user->hasRole('admin');
    }

    // ...existing code...

    public function assignDevice(User $user, Tractor $tractor, GpsDevice $gpsDevice): bool
    {
        return $gpsDevice->user->is($user) && $tractor->gpsDevice()->doesntExist() && $user->hasRole('admin');
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
