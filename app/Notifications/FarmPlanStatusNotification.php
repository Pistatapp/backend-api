<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Notifications\FirebaseMessage;
use Illuminate\Notifications\Notification;
use App\Models\FarmPlan;

class FarmPlanStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private string $message;
    private string $title;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private FarmPlan $plan,
        private string $status
    ) {
        $this->prepareNotificationData();
    }

    /**
     * Prepare notification message and status
     */
    private function prepareNotificationData(): void
    {
        $fields = $this->plan->details->map(function ($detail) {
            return $detail->treatable->name;
        })->join(', ');

        $date = app()->getLocale() === 'fa'
            ? jdate($this->status === 'started' ? $this->plan->start_date : $this->plan->end_date)->format('Y/m/d')
            : ($this->status === 'started' ? $this->plan->start_date : $this->plan->end_date)->format('Y-m-d');

        $this->title = __('Farm Plan Status Notification');

        $this->message = $this->status === 'started'
            ? __('Plan :name implementation started on :date in sections :fields.', [
                'name' => $this->plan->name,
                'date' => $date,
                'fields' => $fields,
            ])
            : __('Plan :name implementation completed on :date in sections :fields.', [
                'name' => $this->plan->name,
                'date' => $date,
                'fields' => $fields,
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
            ->title($this->title)
            ->body($this->message)
            ->data([
                'message' => $this->message,
                'status' => $this->status,
                'color' => $this->status === 'finished' ? 'success' : 'info',
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
            'message' => $this->message,
            'status' => $this->status,
            'color' => $this->status === 'finished' ? 'success' : 'info',
        ];
    }
}
