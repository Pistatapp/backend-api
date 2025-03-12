<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TestBroadcastEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        private string $message,
        private string $channelType = 'public',
        private ?int $userId = null
    ) {
        if ($channelType === 'private' && $userId === null) {
            throw new \InvalidArgumentException('User ID is required for private channel broadcasts');
        }
    }

    public function broadcastAs(): string
    {
        return $this->channelType === 'private' ? 'private.test.broadcast' : 'test.broadcast';
    }

    public function broadcastWith(): array
    {
        $data = [
            'message' => $this->message,
            'timestamp' => now()->toDateTimeString(),
        ];

        if ($this->channelType === 'private') {
            $data['user_id'] = $this->userId;
        }

        return $data;
    }

    public function broadcastOn(): array|Channel
    {
        if ($this->channelType === 'private') {
            return [new PrivateChannel('user.' . $this->userId)];
        }

        return new Channel('test-channel');
    }
}
