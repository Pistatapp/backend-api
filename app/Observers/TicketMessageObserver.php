<?php

namespace App\Observers;

use App\Events\TicketMessageSent;
use App\Events\TicketStatusChanged;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Notifications\NewTicketCreated;
use App\Notifications\TicketReplied;
use Illuminate\Support\Facades\Notification;

class TicketMessageObserver
{
    /**
     * Handle the TicketMessage "created" event.
     */
    public function created(TicketMessage $message): void
    {
        $ticket = $message->ticket;

        // Update parent ticket's last_reply_at and last_reply_by
        $isSupport = $message->is_support_reply;
        $ticket->update([
            'last_reply_by' => $isSupport ? 'support' : 'user',
            'last_reply_at' => now(),
        ]);

        // Reopen ticket if closed
        if ($ticket->isClosed()) {
            $oldStatus = $ticket->status;
            $ticket->reopen();
            
            // Broadcast status change
            event(new TicketStatusChanged($ticket, $oldStatus));
        }

        // Update ticket status
        if ($isSupport) {
            $oldStatus = $ticket->status;
            $ticket->markAsAnswered();
            
            // Broadcast status change if status actually changed
            if ($oldStatus !== $ticket->status) {
                event(new TicketStatusChanged($ticket, $oldStatus));
            }

            // Notify ticket owner
            $ticket->user->notify(new TicketReplied($ticket));
        } else {
            $oldStatus = $ticket->status;
            $ticket->markAsWaiting();
            
            // Broadcast status change if status actually changed
            if ($oldStatus !== $ticket->status) {
                event(new TicketStatusChanged($ticket, $oldStatus));
            }

            // Notify support staff if this is the first message (new ticket)
            if ($ticket->messages()->count() === 1) {
                $supportUsers = \App\Models\User::role(['admin', 'support'])->get();
                Notification::send($supportUsers, new NewTicketCreated($ticket));
            }
        }

        // Broadcast message sent event
        event(new TicketMessageSent($message));
    }
}

