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

class ReportReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        private array $points,
        private GpsDevice $device,
    ) {
        //
    }

    /**
     * Get the event name.
     *
     * @return array
     */
    public function broadcastAs(): string
    {
        return 'report-received';
    }

    /**
     * Check if the event should be broadcast.
     *
     * @return bool
     */
    public function broadcastWhen(): bool
    {
        return !empty($this->points);
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        return collect($this->points)->map(function ($point) {
            return [
                    'latitude' => $point['coordinate'][0],
                    'longitude' => $point['coordinate'][1],
                    'speed' => $point['speed'],
                    'status' => $point['status'],
                    'directions' => $point['directions'],
                    'is_starting_point' => $point['is_starting_point'],
                    'is_ending_point' => $point['is_ending_point'],
                    'is_stopped' => $point['is_stopped'],
                    'stoppage_time' => gmdate('H:i:s', $point['stoppage_time']),
                    'date_time' => jdate($point['date_time'])->format('Y/m/d H:i:s'),
                ];
        })->toArray();
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): Channel
    {
        return new PrivateChannel('gps_devices.' . $this->device->id);
    }
}
