<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Notifications\FirebaseMessage;
use Illuminate\Notifications\Notification;
use App\Models\Tractor;
use Carbon\Carbon;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Support\Facades\File;

class TractorInactivityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private string $message;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private Tractor $tractor,
        private Carbon $lastActivity,
        private int $threshold,
        private Carbon $date
    ) {
        $this->prepareNotificationData();
    }

    /**
     * Prepare notification data
     */
    private function prepareNotificationData(): void
    {
        $this->message = __('Tractor :tractor_name has been inactive for more than :days days since :time on :date. Please check the reason.', [
            'tractor_name' => $this->tractor->name,
            'days' => $this->threshold,
            'time' => $this->lastActivity->format('H:i'),
            'date' => jdate($this->date)->format('Y/m/d')
        ]);
    }

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
            'message' => $this->message,
            'last_activity' => $this->lastActivity->format('Y-m-d H:i:s'),
            'threshold' => $this->threshold,
            'color' => 'warning',
        ];
    }

    /**
     * Get the notification's database type.
     */
    public function databaseType(object $notifiable): string
    {
        return 'tractor_inactivity';
    }

    /**
     * Get the notification's Firebase message.
     */
    public function toFirebase(object $notifiable): FirebaseMessage
    {
        return (new FirebaseMessage)
            ->title('Tractor Inactivity Warning')
            ->body($this->message)
            ->data([
                'tractor_id' => (string) $this->tractor->id,
                'type' => 'tractor_inactivity',
                'threshold' => (string) $this->threshold,
                'last_activity' => $this->lastActivity->format('Y-m-d H:i:s'),
                'color' => 'warning',
            ]);
    }

    /**
     * Get the last activity time.
     */
    public function getLastActivity(): Carbon
    {
        return $this->lastActivity;
    }

    /**
     * Get the inactivity threshold in days.
     */
    public function getThreshold(): int
    {
        return $this->threshold;
    }

    /**
     * Get the tractor.
     */
    public function getTractor(): Tractor
    {
        return $this->tractor;
    }

    /**
     * Get the notification date.
     */
    public function getDate(): Carbon
    {
        return $this->date;
    }
}
