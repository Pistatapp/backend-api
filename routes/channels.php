<?php

use App\Models\GpsDevice;
use App\Models\Irrigation;
use App\Models\User;
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

Broadcast::channel('gps_devices.{gps_device}', function (User $user, GpsDevice $gps_device) {
    return $gps_device->user->is($user);
});

Broadcast::channel('irrigations.{irrigation}', function (User $user, Irrigation $irrigation) {
    return $irrigation->creator->is($user);
});
