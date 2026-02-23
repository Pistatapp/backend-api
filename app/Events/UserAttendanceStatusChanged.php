<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserAttendanceStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public User $user,
        public array $gpsData,
    ) {}

    public function broadcastAs(): string
    {
        return 'attendance.status.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->profile->name,
            ],
            'coordinate' => $this->gpsData['coordinate'],
            'date_time' => now()->toIso8601String(),
        ];
    }

    public function broadcastOn(): array
    {
        $farmId = $this->user->attendanceTracking->farm_id;

        return [
            new Channel('attendance.status'),
            new PrivateChannel('farm.' . $farmId . '.attendance'),
        ];
    }
}
