<?php

namespace App\Events;

use App\Models\TractorTask;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TractorTaskStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public TractorTask $task,
        public string $status
    ) {
        //
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'tractor.task.status_changed';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        // Get work duration from GPS metrics
        $metrics = \App\Models\GpsMetricsCalculation::where('tractor_task_id', $this->task->id)
            ->where('date', $this->task->date)
            ->first();

        $workDuration = $metrics ? $metrics->work_duration : 0;

        return [
            'task_id' => $this->task->id,
            'tractor_id' => $this->task->tractor_id,
            'status' => $this->status, // pending, started, finished
            'operation' => [
                'id' => $this->task->operation->id,
                'name' => $this->task->operation->name,
            ],
            'taskable' => [
                'id' => $this->task->taskable->id,
                'name' => $this->task->taskable->name,
            ],
            'start_time' => $this->task->start_time->format('H:i:s'),
            'end_time' => $this->task->end_time->format('H:i:s'),
            'work_duration' => $workDuration,
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('tractor.tasks.' . $this->task->id);
    }
}
