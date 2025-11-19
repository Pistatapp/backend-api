<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class TicketReplied extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private Ticket $ticket
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function via($notifiable): array
    {
        return ['database', 'firebase'];
    }

    /**
     * Get the Firebase representation of the notification.
     *
     * @param object $notifiable
     * @return FirebaseMessage
     */
    public function toFirebase($notifiable): FirebaseMessage
    {
        return (new FirebaseMessage)
            ->title(__('Support Reply'))
            ->body(__('Support has replied to your ticket #:ticket_id', ['ticket_id' => $this->ticket->id]))
            ->data([
                'priority' => 'high',
                'type' => 'ticket_replied',
                'ticket_id' => $this->ticket->id,
                'title' => __('Support Reply'),
                'body' => __('Support has replied to your ticket #:ticket_id', ['ticket_id' => $this->ticket->id]),
            ]);
    }

    /**
     * Get the database representation of the notification.
     *
     * @param object $notifiable
     * @return array
     */
    public function toDatabase($notifiable): array
    {
        return [
            'title' => __('Support Reply'),
            'body' => __('Support has replied to your ticket #:ticket_id', ['ticket_id' => $this->ticket->id]),
            'type' => 'ticket_replied',
            'ticket_id' => $this->ticket->id,
        ];
    }
}

