<?php

namespace App\Events;

use App\Models\Employee;
use App\Models\WorkerAttendanceSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkerAttendanceUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Employee $employee,
        public WorkerAttendanceSession $session
    ) {
        //
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'worker.attendance.updated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith()
    {
        return [
            'employee' => [
                'id' => $this->employee->id,
                'name' => $this->employee->full_name,
            ],
            'session' => [
                'id' => $this->session->id,
                'date' => $this->session->date->toDateString(),
                'entry_time' => $this->session->entry_time?->toIso8601String(),
                'exit_time' => $this->session->exit_time?->toIso8601String(),
                'total_in_zone_duration' => $this->session->total_in_zone_duration,
                'total_out_zone_duration' => $this->session->total_out_zone_duration,
                'status' => $this->session->status,
            ],
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('worker.attendance'),
            new PrivateChannel('farm.' . $this->employee->farm_id . '.workers'),
        ];
    }
}
