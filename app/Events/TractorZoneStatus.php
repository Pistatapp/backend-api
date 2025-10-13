<?php

namespace App\Events;

use App\Models\GpsDevice;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TractorZoneStatus implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public array $zoneData,
        public GpsDevice $device
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tractor.' . $this->device->tractor->id),
        ];
    }

    /**
     * The event's broadcast name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'tractor.zone.status';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        return [
            'tractor_id' => $this->device->tractor->id,
            'device_id' => $this->device->id,
            'is_in_task_zone' => $this->zoneData['is_in_task_zone'],
            'task_id' => $this->zoneData['task_id'],
            'task_name' => $this->zoneData['task_name'],
            'work_duration_in_zone' => $this->zoneData['work_duration_in_zone'],
        ];
    }
}
