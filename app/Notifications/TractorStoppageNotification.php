<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Notifications\FirebaseMessage;
use Illuminate\Notifications\Notification;
use App\Models\Tractor;
use Carbon\Carbon;

class TractorStoppageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private string $message;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private Tractor $tractor,
        private int $stoppageDuration,
        private int $threshold,
        private Carbon $date
    ) {
        $this->prepareNotificationData();
    }

    /**
     * Prepare notification message
     */
    private function prepareNotificationData(): void
    {
        $this->message = __('Tractor :tractor_name has been stopped for more than :hours hours on :date. Please check the reason.', [
            'tractor_name' => $this->tractor->name,
            'hours' => $this->threshold,
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
     * Get the Firebase Cloud Messaging representation of the notification.
     */
    public function toFireBase(object $notifiable): FirebaseMessage
    {
        return (new FirebaseMessage)
            ->title(__('Tractor Stoppage Warning'))
            ->body($this->message)
            ->data([
                'message' => $this->message,
                'stoppage_duration' => $this->stoppageDuration,
                'threshold' => $this->threshold,
                'color' => 'warning',
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable): array
    {
        return [
            'message' => $this->message,
            'stoppage_duration' => $this->stoppageDuration,
            'threshold' => $this->threshold,
            'color' => 'warning',
        ];
    }

    /**
     * Get the stoppage duration for testing purposes.
     */
    public function getStoppageDuration(): int
    {
        return $this->stoppageDuration;
    }

    /**
     * Get the threshold for testing purposes.
     */
    public function getThreshold(): int
    {
        return $this->threshold;
    }
}
