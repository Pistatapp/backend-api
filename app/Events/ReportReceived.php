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

    public function getDevice(): GpsDevice
    {
        return $this->device;
    }

    public function getData(): array
    {
        return $this->data;
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
            'tractor_id' => $this->data['tractor_id'],
            'traveled_distance' => number_format($this->data['traveled_distance'], 2),
            'work_duration' => gmdate('H:i:s', $this->data['work_duration']),
            'stoppage_duration' => gmdate('H:i:s', $this->data['stoppage_duration']),
            'efficiency' => number_format($this->data['efficiency'], 2),
            'stoppage_count' => $this->data['stoppage_count'],
            'speed' => $this->data['speed'],
            'points' => collect($this->data['points'])->map(function ($point) {
                return [
                    'latitude' => $point['coordinate'][0],
                    'longitude' => $point['coordinate'][1],
                    'speed' => $point['speed'],
                    'status' => $point['status'],
                    'ew_direction' => $point['ew_direction'],
                    'ns_direction' => $point['ns_direction'],
                    'is_starting_point' => $point['is_starting_point'],
                    'is_ending_point' => $point['is_ending_point'],
                    'is_stopped' => $point['is_stopped'],
                    'is_off' => $point['is_off'],
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
