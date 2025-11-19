<?php

use App\Models\GpsDevice;
use App\Models\Irrigation;
use App\Models\Tractor;
use App\Models\Ticket;
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

Broadcast::channel('gps_devices.{gps_device}', function (User $user, GpsDevice $gps_device) {
    return true;
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

Broadcast::channel('support.tickets.{ticketId}', function (User $user, $ticketId) {
    $ticket = Ticket::find($ticketId);
    if (!$ticket) {
        return false;
    }
    
    // User can listen if they own the ticket or are support staff
    return $user->id === $ticket->user_id || $user->hasRole(['admin', 'support']);
});
