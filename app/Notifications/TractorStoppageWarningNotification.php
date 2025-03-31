<?php

namespace App\Notifications;

use App\Models\GpsDailyReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class TractorStoppageWarningNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private string $message;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private GpsDailyReport $report,
        private float $warningHours
    ) {
        $this->prepareMessage();
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['database', 'firebase'];
    }

    /**
     * Get the Firebase representation of the notification.
     */
    public function toFirebase(object $notifiable): FirebaseMessage
    {
        return (new FirebaseMessage)
            ->title(__('Tractor Stoppage Warning'))
            ->body($this->message)
            ->data([
                'message' => $this->message,
                'type' => 'warning',
                'color' => 'warning',
                'tractor_id' => $this->report->tractor_id,
                'date' => $this->report->date,
                'warning_hours' => $this->warningHours,
                'actual_hours' => $this->report->stoppage_duration / 3600
            ]);
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => $this->message,
            'type' => 'warning',
            'color' => 'warning',
            'tractor_id' => $this->report->tractor_id,
            'date' => $this->report->date,
            'warning_hours' => $this->warningHours,
            'actual_hours' => $this->report->stoppage_duration / 3600
        ];
    }

    private function prepareMessage(): void
    {
        $tractor = $this->report->tractor;
        $this->message = __('Tractor :name has been stopped for more than :hours hours on :date', [
            'name' => $tractor->name,
            'hours' => $this->warningHours,
            'date' => jdate($this->report->date)->format('Y/m/d')
        ]);
    }
}
