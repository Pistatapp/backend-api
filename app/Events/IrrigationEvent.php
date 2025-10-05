<?php

namespace App\Events;

use App\Models\Irrigation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * This event handles both irrigation start and finish states
 * It replaces the separate IrrigationStarted and IrrigationFinished events
 */
class IrrigationEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Irrigation $irrigation,
        public string $status,
        public string $eventType, // 'started' or 'finished'
    ) {}

    /**
     * Get the event name.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return 'irrigation-status-changed';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'data' => [
                'id' => $this->irrigation->id,
                'status' => $this->status,
                'duration' => $this->irrigation->duration,
                'plots' => $this->irrigation->plots->map(fn ($plot) => [
                    'id' => $plot->id,
                    'name' => $plot->name,
                ]),
                'valves' => $this->irrigation->valves->map(fn ($valve) => [
                    'id' => $valve->id,
                    'name' => $valve->name,
                    'status' => $valve->pivot->status,
                    'opened_at' => $valve->pivot->opened_at?->format('H:i'),
                    'closed_at' => $valve->pivot->closed_at?->format('H:i'),
                ]),
            ]
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
            new PrivateChannel('irrigations.' . $this->irrigation->id),
        ];
    }
}
