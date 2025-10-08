<?php

namespace App\Listeners;

use App\Events\TractorTaskStatusChanged;
use App\Models\GpsMetricsCalculation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Notifications\TractorTaskStatusNotification;

class TractorTaskStatusChangedListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(TractorTaskStatusChanged $event): void
    {
        // Only send notifications when task is completed (done or not_done)
        if (!in_array($event->status, ['done', 'not_done'])) {
            return;
        }

        // Get the GPS daily report for this task
        $dailyReport = GpsMetricsCalculation::where('tractor_task_id', $event->task->id)
            ->where('date', $event->task->date)
            ->first();

        // Send notification to the task creator
        $event->task->creator->notify(new TractorTaskStatusNotification($event->task, $dailyReport));
    }
}
