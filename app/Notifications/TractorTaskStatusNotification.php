<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Notifications\FirebaseMessage;
use Illuminate\Notifications\Notification;
use App\Models\TractorTask;
use App\Models\GpsDailyReport;

class TractorTaskStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private string $message;
    private bool $success;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private TractorTask $task,
        private ?GpsDailyReport $dailyReport = null
    ) {
        $this->prepareNotificationData();
    }

    /**
     * Prepare notification message and status
     */
    private function prepareNotificationData(): void
    {
        $taskCompleted = $this->dailyReport && $this->dailyReport->traveled_distance > 0;

        $duration = '';
        if ($taskCompleted) {
            $hours = floor($this->dailyReport->work_duration / 3600);
            $minutes = floor(($this->dailyReport->work_duration % 3600) / 60);
            $duration = sprintf("%d:%02d", $hours, $minutes);
        }

        $date = jdate($this->task->date)->format('Y/m/d');

        // Get the taskable name (field, farm, plot, etc.)
        $taskableName = $this->task->taskable->name ?? $this->task->taskable->title ?? 'Unknown';

        $this->success = $taskCompleted;
        $this->message = $taskCompleted
            ? __('On :date, tractor :tractor_name successfully completed :operation operation in :taskable_type :taskable_name for :duration.', [
                'date' => $date,
                'tractor_name' => $this->task->tractor->name,
                'duration' => $duration,
                'taskable_type' => $this->getTaskableTypeName(),
                'taskable_name' => $taskableName,
                'operation' => $this->task->operation->name
            ])
            : __('On :date, tractor :tractor_name did not complete the assigned task and did not enter :taskable_type :taskable_name for :operation operation at the scheduled time.', [
                'date' => $date,
                'tractor_name' => $this->task->tractor->name,
                'taskable_type' => $this->getTaskableTypeName(),
                'taskable_name' => $taskableName,
                'operation' => $this->task->operation->name
            ]);
    }

    /**
     * Get a human-readable name for the taskable type
     */
    private function getTaskableTypeName(): string
    {
        return match ($this->task->taskable_type) {
            'App\\Models\\Field' => 'field',
            'App\\Models\\Farm' => 'farm',
            'App\\Models\\Plot' => 'plot',
            default => 'area'
        };
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
            ->title(__('Tractor Task Status Notification'))
            ->body($this->message)
            ->data([
                'message' => $this->message,
                'success' => $this->success,
                'color' => $this->success ? 'success' : 'danger',
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
            'success' => $this->success,
            'color' => $this->success ? 'success' : 'danger',
        ];
    }
}
