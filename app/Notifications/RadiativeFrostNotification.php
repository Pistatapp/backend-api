<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Notifications\FirebaseMessage;

class RadiativeFrostNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public readonly float $averageTemp,
        public readonly float $dewPoint,
        public readonly string $date
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'average_temp' => $this->averageTemp,
            'dew_point' => $this->dewPoint,
            'date' => $this->date,
            'message' => __('There is a risk of radiative frost on :date. Take precautions.', [
                'date' => $this->date
            ])
        ];
    }

    /**
     * Get the firebase representation of the notification.
     */
    public function toFirebase(object $notifiable): FirebaseMessage
    {
        return (new FirebaseMessage)
            ->title(__('Radiative Frost Warning'))
            ->body(__('There is a risk of radiative frost on :date. Take precautions.', [
                'date' => $this->date
            ]))
            ->data([
                'average_temp' => (string) $this->averageTemp,
                'dew_point' => (string) $this->dewPoint,
                'date' => $this->date
            ]);
    }
}
