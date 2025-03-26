<?php

namespace App\Listeners;

use App\Events\FarmPlanStatusChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Notifications\FarmPlanStatusNotification;
use Illuminate\Support\Facades\Notification;

class FarmPlanStatusChangedListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(FarmPlanStatusChanged $event): void
    {
        // Only notify on started and finished status
        if (!in_array($event->status, ['started', 'finished'])) {
            return;
        }

        // Load the plan's details and related models
        $event->plan->load(['details.treatable', 'creator', 'farm.users']);

        // Send notification to both the plan creator and farm owner
        $notifiables = $event->plan->farm->users;

        Notification::send($notifiables, new FarmPlanStatusNotification($event->plan, $event->status));
    }
}
