<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Notifications\FirebaseMessage;

class FrostWarningNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private float $temperature,
        private string $date,
        private int $days
    ) {}

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['database', 'firebase'];
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'temperature' => $this->temperature,
            'date' => $this->date,
            'days' => $this->days,
            'message' => __('There is a risk of frost in your farm in the next :days days. Take precautions.', [
                'days' => $this->days
            ])
        ];
    }

    /**
     * Get the firebase representation of the notification.
     */
    public function toFirebase(object $notifiable): FirebaseMessage
    {
        return (new FirebaseMessage)
            ->title(__('Frost Warning'))
            ->body(__('There is a risk of frost in your farm in the next :days days. Take precautions.', [
                'days' => $this->days
            ]))
            ->data([
                'temperature' => (string) $this->temperature,
                'date' => $this->date,
                'days' => (string) $this->days
            ]);
    }
}
