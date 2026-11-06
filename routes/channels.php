<?php

use App\Models\GpsDevice;
use App\Models\Irrigation;
use App\Models\Tractor;
use App\Models\User;
use App\Models\TractorTask;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('gps_devices.{gps_device}', function (User $user, GpsDevice $gps_device): bool {
    // A GPS device is only subscribable when it is currently assigned to a
    // tractor that the authenticated user may view.  This also fails closed
    // for unassigned/unknown devices instead of relying on device ownership.
    $tractor = $gps_device->tractor;

    return $tractor !== null && $user->can('view', $tractor);
});

Broadcast::channel('irrigations.{irrigation}', function (User $user, Irrigation $irrigation) {
    return true;
});

Broadcast::channel('users.{userId}', function (User $user, $userId) {
    return (int) $user->id === (int) $userId;
});

Broadcast::channel('tractor.{tractor}', function(User $user, Tractor $tractor) {
    return true;
});

Broadcast::channel('tractor.tasks.{tractorTask}', function (User $user, TractorTask $tractorTask) {
    return true;
});
