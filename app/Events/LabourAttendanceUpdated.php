<?php

namespace App\Events;

use App\Models\Labour;
use App\Models\LabourAttendanceSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LabourAttendanceUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Labour $labour,
        public LabourAttendanceSession $session
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
        return 'labour.attendance.updated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith()
    {
        return [
            'labour' => [
                'id' => $this->labour->id,
                'name' => $this->labour->name,
            ],
            'session' => [
                'id' => $this->session->id,
                'date' => $this->session->date->toDateString(),
                'entry_time' => $this->session->entry_time?->toIso8601String(),
                'exit_time' => $this->session->exit_time?->toIso8601String(),
                'in_zone_duration' => $this->session->in_zone_duration,
                'outside_zone_duration' => $this->session->outside_zone_duration,
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
            new Channel('labour.attendance'),
            new PrivateChannel('farm.' . $this->labour->farm_id . '.labours'),
        ];
    }
}

