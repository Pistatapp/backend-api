<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewTicketCreated extends Notification implements ShouldQueue
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
        return ['database'];
    }

    /**
     * Get the database representation of the notification.
     *
     * @param object $notifiable
     * @return array
     */
    public function toDatabase($notifiable): array
    {
        $user = $this->ticket->user;
        $userName = $user->username ?? $user->mobile;

        return [
            'title' => __('New Support Ticket'),
            'body' => __('New support ticket from :user', ['user' => $userName]),
            'type' => 'new_ticket',
            'ticket_id' => $this->ticket->id,
            'user_id' => $this->ticket->user_id,
        ];
    }
}

