<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Models\TractorTask;
use App\Notifications\FirebaseMessage;
use Kavenegar\Laravel\Message\KavenegarMessage;
use Kavenegar\Laravel\Notification\KavenegarBaseNotification;

class TractorTaskCreated extends KavenegarBaseNotification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public TractorTask $task
    ) {
        // Eager load necessary relationships
        $this->task->load('tractor', 'operation', 'taskable');
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param object $notifiable
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        // If notifiable is a Driver, send only SMS
        if ($notifiable instanceof \App\Models\Driver) {
            return ['kavenegar'];
        }

        // If notifiable is a User (farm admin), send database
        return ['database'];
    }

    /**
     * Get the Firebase Cloud Messaging representation of the notification.
     *
     * @param object $notifiable
     * @return FirebaseMessage
     */
    public function toFireBase(object $notifiable): FirebaseMessage
    {
        $tractorName = $this->task->tractor->name;
        $operationName = $this->task->operation->name;
        $startTime = $this->task->start_time->format('H:i');
        $endTime = $this->task->end_time->format('H:i');
        $taskDate = jdate($this->task->date)->format('Y/m/d');
        $taskableName = $this->task->taskable->name ?? 'نامشخص';

        return (new FirebaseMessage)
            ->title(__('notifications.tractor_task_created.title', ['tractor_name' => $tractorName]))
            ->body(__('notifications.tractor_task_created.body', [
                'operation_name' => $operationName,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'task_date' => $taskDate,
                'taskable_name' => $taskableName
            ]))
            ->data(['task_id' => (string) $this->task->id, 'color' => 'info']);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param object $notifiable
     * @return array<string, mixed>
     */
    public function toArray($notifiable): array
    {
        $tractorName = $this->task->tractor->name;
        $operationName = $this->task->operation->name;
        $startTime = $this->task->start_time->format('H:i');
        $endTime = $this->task->end_time->format('H:i');
        $taskDate = jdate($this->task->date)->format('Y/m/d');
        $taskableName = $this->task->taskable->name ?? 'نامشخص';

        return [
            'title' => __('notifications.tractor_task_created.title', ['tractor_name' => $tractorName]),
            'message' => __('notifications.tractor_task_created.body', [
                'operation_name' => $operationName,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'task_date' => $taskDate,
                'taskable_name' => $taskableName
            ]),
            'task_id' => $this->task->id,
            'color' => 'info',
        ];
    }

    /**
     * Get the Kavenegar SMS representation of the notification.
     *
     * @param object $notifiable
     * @return KavenegarMessage
     */
    public function toKavenegar($notifiable): KavenegarMessage
    {
        $operationName = $this->task->operation->name;
        $startTime = $this->task->start_time;
        $endTime = $this->task->end_time;
        $taskDate = jdate($this->task->date)->format('Y/m/d');


        return (new KavenegarMessage)
            ->verifyLookup('tractor-task-message', [
                'token' => $operationName,
                'token2' => $taskDate,
                'token3' => $endTime,
                'token10' => $startTime,
            ]);
    }
}
