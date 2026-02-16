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
        $farmId = $this->user->attendanceTracking?->farm_id;

        return [
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->profile?->name ?? $this->user->mobile,
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

    public function broadcastOn(): array
    {
        $farmId = $this->user->attendanceTracking?->farm_id ?? 0;

        return [
            new Channel('attendance'),
            new PrivateChannel('farm.' . $farmId . '.attendance'),
        ];
    }
}
