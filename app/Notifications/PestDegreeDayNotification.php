<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Notifications\FirebaseMessage;

class PestDegreeDayNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $pest,
        public string $startDate,
        public string $endDate,
        public float $requiredDegreeDays,
        public float $actualDegreeDays
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
     * Get the firebase representation of the notification.
     */
    public function toFirebase(object $notifiable): FirebaseMessage
    {
        return (new FirebaseMessage)
            ->title(__('Pest Degree Day Warning'))
            ->body($this->getMessage())
            ->data([
                'pest' => $this->pest,
                'start_date' => $this->startDate,
                'end_date' => $this->endDate,
                'required_degree_days' => (string) $this->requiredDegreeDays,
                'actual_degree_days' => (string) $this->actualDegreeDays,
                'type' => 'pest_degree_day',
                'priority' => 'high'
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => $this->getMessage(),
            'pest' => $this->pest,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'required_degree_days' => $this->requiredDegreeDays,
            'actual_degree_days' => $this->actualDegreeDays,
        ];
    }

    /**
     * Get formatted warning message.
     */
    private function getMessage(): string
    {
        return __('The degree days for :pest pest from :start_date to :end_date was :degree_days.', [
            'pest' => $this->pest,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'degree_days' => $this->actualDegreeDays
        ]);
    }
}
