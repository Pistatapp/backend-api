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
        private TractorTask $task,
        private string $status
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
        return [
            'task_id' => $this->task->id,
            'tractor_id' => $this->task->tractor_id,
            'status' => $this->status, // pending, started, finished
            'operation' => [
                'id' => $this->task->operation->id,
                'name' => $this->task->operation->name,
            ],
            'field' => [
                'id' => $this->task->field->id,
                'name' => $this->task->field->name,
            ],
            'start_time' => $this->task->start_time,
            'end_time' => $this->task->end_time,
            'updated_at' => now()->toIso8601String(),
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
