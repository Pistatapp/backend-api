<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Notifications\FirebaseMessage;

class FrostbiteRiskNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private array $daysWithRisk
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'firebase'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'days_with_risk' => $this->daysWithRisk,
            'message' => __('Frostbite risk detected on the following days: ' . implode(', ', array_map(function ($day) {
                return $day['day'] . ' (' . $day['date'] . ')';
            }, $this->daysWithRisk))),
        ];
    }

    /**
     * Get the firebase representation of the notification.
     *
     * @return FirebaseMessage
     */
    public function toFirebase(object $notifiable): FirebaseMessage
    {
        return (new FirebaseMessage)
            ->title(__('Frostbite Risk Alert'))
            ->body(__('Frostbite risk detected on the following days: ' . implode(', ', array_map(function ($day) {
                return $day['day'] . ' (' . $day['date'] . ')';
            }, $this->daysWithRisk))))
            ->data(['days_with_risk' => $this->daysWithRisk]);
    }
}
