<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class IrrigationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private object $irrigation,
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
            ->body($this->getBodyMessage())
            ->data([
                'priority' => 'high',
                'title' => __('Irrigation Notification'),
                'body' => $this->getBodyMessage(),
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
            'title' => __('Irrigation Notification'),
            'body' => $this->getBodyMessage(),
        ];
    }


    /**
     * Get the body message for the notification.
     *
     * @return string
     */
    private function getBodyMessage(): string
    {
        return __('The :plots irrigation status is :status.', [
            'plots' => $this->irrigation->plots->pluck('name')->join(', '),
            'status' => __($this->irrigation->status),
        ]);
    }
}
