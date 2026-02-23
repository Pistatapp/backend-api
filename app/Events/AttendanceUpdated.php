<?php

namespace App\Events;

use App\Models\User;
use App\Models\AttendanceSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AttendanceUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public User $user,
        public AttendanceSession $session
    ) {
        //
    }

    public function broadcastAs(): string
    {
        return 'attendance.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->profile->name,
            ],
            'session' => [
                'id' => $this->session->id,
                'date' => $this->session->date->toDateString(),
                'entry_time' => $this->session->entry_time?->format('H:i:s'),
                'exit_time' => $this->session->exit_time?->format('H:i:s'),
                'in_zone_duration' => $this->session->in_zone_duration,
                'outside_zone_duration' => $this->session->outside_zone_duration,
                'status' => $this->session->status,
            ],
        ];
    }

    public function broadcastOn(): array
    {
        $farmId = $this->user->attendanceTracking->farm_id;

        return [
            new Channel('attendance'),
            new PrivateChannel('farm.' . $farmId . '.attendance'),
        ];
    }
}
