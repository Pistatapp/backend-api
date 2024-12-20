<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class IrrigationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $status
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['firebase', 'database'];
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
            ->title(__('Irrigation Notification'))
            ->body(__('The irrigation has been ') . $this->status)
            ->data([
                'priority' => 'high',
                'title' => __('Irrigation Notification'),
                'body' => __('The irrigation has been ') . $this->status,
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            'message' => 'The irrigation has been ' . $this->status,
        ];
    }
}
