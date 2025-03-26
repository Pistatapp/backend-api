<?php

namespace App\Events;

use App\Models\FarmPlan;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FarmPlanStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public FarmPlan $plan,
        public string $status
    ) {
        //
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'farm.plan.status_changed';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'plan_id' => $this->plan->id,
            'farm_id' => $this->plan->farm_id,
            'status' => $this->status,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('farm.plans.' . $this->plan->id);
    }
}
