<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Notifications\FirebaseMessage;

class OilSprayNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly int $requiredHours,
        public readonly int $actualHours
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
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'required_hours' => $this->requiredHours,
            'actual_hours' => $this->actualHours,
            'message' => __('The chilling requirement in your farm from :start_date to :end_date was :hours hours. Please perform oil spraying.', [
                'start_date' => $this->startDate,
                'end_date' => $this->endDate,
                'hours' => $this->actualHours
            ])
        ];
    }

    /**
     * Get the firebase representation of the notification.
     */
    public function toFirebase(object $notifiable): FirebaseMessage
    {
        return (new FirebaseMessage)
            ->title(__('Volk Oil Spray Warning'))
            ->body(__('The chilling requirement in your farm from :start_date to :end_date was :hours hours. Please perform oil spraying.', [
                'start_date' => $this->startDate,
                'end_date' => $this->endDate,
                'hours' => $this->actualHours
            ]))
            ->data([
                'start_date' => $this->startDate,
                'end_date' => $this->endDate,
                'required_hours' => (string) $this->requiredHours,
                'actual_hours' => (string) $this->actualHours
            ]);
    }
}
