<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Notifications\FirebaseMessage;
use Illuminate\Notifications\Notification;
use App\Models\TractorTask;
use App\Models\TractorTaskTaskable;
use App\Models\GpsMetricsCalculation;
use App\Services\TractorEfficiencyService;

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
        private ?GpsMetricsCalculation $dailyReport = null
    ) {
        $this->prepareNotificationData();
    }

    /**
     * Prepare notification message and status
     */
    private function prepareNotificationData(): void
    {
        $taskCompleted = $this->dailyReport && $this->task->status === 'done';
        $efficiencyService = app(TractorEfficiencyService::class);
        $presenceDurationSeconds = $this->dailyReport
            ? $efficiencyService->taskPresenceDurationSeconds($this->dailyReport)
            : 0;
        $efficiency = $presenceDurationSeconds > 0
            ? $efficiencyService->calculate($this->task->tractor, $presenceDurationSeconds)
            : 0;

        $date = jdate($this->task->date)->format('Y/m/d');
        $startTime = $this->task->start_time->format('H:i');
        $endTime = $this->task->end_time->format('H:i');
        $duration = to_time_format($presenceDurationSeconds);
        $efficiency = number_format($efficiency, 2, '.', '');

        $this->task->loadMissing('tractor', 'operation');

        $taskableNamesList = $this->allTaskableNamesForMessage();

        $this->success = $taskCompleted;
        $this->message = $taskCompleted
            ? __('On :date, tractor :tractor_name completed :operation operation in :taskable_type :taskable_name during :start_time to :end_time with :duration of presence and :efficiency% efficiency.', [
                'date' => $date,
                'tractor_name' => $this->task->tractor->name,
                'duration' => $duration,
                'efficiency' => $efficiency,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'taskable_type' => $this->getTaskableTypeName(),
                'taskable_name' => $taskableNamesList,
                'operation' => $this->task->operation->name
            ])
            : __('On :date, tractor :tractor_name did not complete :operation operation in :taskable_type :taskable_name during :start_time to :end_time. Presence: :duration; efficiency: :efficiency%.', [
                'date' => $date,
                'tractor_name' => $this->task->tractor->name,
                'duration' => $duration,
                'efficiency' => $efficiency,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'taskable_type' => $this->getTaskableTypeName(),
                'taskable_name' => $taskableNamesList,
                'operation' => $this->task->operation->name
            ]);
    }

    /**
     * Comma-separated names for every linked taskable (fresh query for queued jobs).
     */
    private function allTaskableNamesForMessage(): string
    {
        $labels = $this->task->taskableItems()
            ->orderBy('sort_order')
            ->with('taskable')
            ->get()
            ->map(function (TractorTaskTaskable $row) {
                $m = $row->taskable;

                return $m ? ($m->name ?? $m->title ?? null) : null;
            })
            ->filter()
            ->unique()
            ->values();

        return $labels->isNotEmpty()
            ? $labels->implode(', ')
            : (string) __('Unknown');
    }

    /**
     * Human-readable taskable type (pluralized for fields when multiple areas).
     */
    private function getTaskableTypeName(): string
    {
        $items = $this->task->taskableItems()->orderBy('sort_order')->get();
        if ($items->isEmpty()) {
            return (string) __('area');
        }

        $plural = $items->count() > 1;
        $type = $items->first()->taskable_type;

        return match ($type) {
            'App\\Models\\Field' => $plural ? (string) __('fields') : (string) __('field'),
            'App\\Models\\Farm' => (string) __('farm'),
            'App\\Models\\Plot' => (string) __('plot'),
            'App\\Models\\Row' => (string) __('row'),
            default => (string) __('area'),
        };
    }

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
     * Get the Firebase Cloud Messaging representation of the notification.
     */
    public function toFireBase(object $notifiable): FirebaseMessage
    {
        return (new FirebaseMessage)
            ->title(__('Tractor Task Status Notification'))
            ->body($this->message)
            ->data([
                'message' => $this->message,
                'success' => $this->success ? 'true' : 'false',
                'color' => $this->success ? 'success' : 'danger',
                'task_id' => (string) $this->task->id,
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
            'task_id' => $this->task->id,
        ];
    }
}
