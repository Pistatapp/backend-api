<?php

use App\Models\GpsDevice;
use App\Models\Irrigation;
use App\Models\User;
use App\Models\TractorTask;
use Illuminate\Support\Facades\Auth;
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
    return Auth::user()->is($user);
});

Broadcast::channel('irrigations.{irrigation}', function (User $user, Irrigation $irrigation) {
    return true;
});

Broadcast::channel('user.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('tractor.tasks.{tractorTask}', function (User $user, TractorTask $tractorTask) {
    return true;
});

// Public test channel for testing WebSocket functionality
Broadcast::channel('test-channel.{user}', function (User $user, $id) {
    return true; // Public channel - anyone can listen
});
