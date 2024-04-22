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
        private array $data,
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
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->data['id'],
            'trucktor_id' => $this->data['trucktor_id'],
            'traveled_distance' => number_format($this->data['traveled_distance'], 2),
            'work_duration' => gmdate('H:i:s', $this->data['work_duration']),
            'stoppage_duration' => gmdate('H:i:s', $this->data['stoppage_duration']),
            'efficiency' => number_format($this->data['efficiency'], 2),
            'stoppage_count' => $this->data['stoppage_count'],
            'speed' => $this->data['speed'],
            'points' => collect($this->data['points'])->map(function ($point) {
                return [
                    'latitude' => $point['latitude'],
                    'longitude' => $point['longitude'],
                    'speed' => $point['speed'],
                    'status' => $point['status'],
                    'is_starting_point' => $point['is_starting_point'],
                    'is_ending_point' => $point['is_ending_point'],
                    'is_stopped' => $point['is_stopped'],
                    'stoppage_time' => gmdate('H:i:s', $point['stoppage_time']),
                    'date_time' => jdate($point['date_time'])->format('Y/m/d H:i:s'),
                ];
            })
        ];
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
