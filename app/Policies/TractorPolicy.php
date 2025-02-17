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
        return $tractor->farm->user->is($user);
    }

    // ...existing code...

    public function delete(User $user, Tractor $tractor): bool
    {
        return $tractor->farm->user->is($user);
    }

    // ...existing code...

    public function assignDevice(User $user, Tractor $tractor, GpsDevice $gpsDevice): bool
    {
        return $gpsDevice->user->is($user) && $tractor->gpsDevice()->doesntExist();
    }
}
