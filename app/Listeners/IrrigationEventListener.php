<?php

namespace App\Listeners;

use App\Events\IrrigationEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Notifications\IrrigationNotification;

class IrrigationEventListener
{
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
    public function handle(IrrigationEvent $event): void
    {
        $eventType = $event->eventType;
        if ($eventType === 'started') {
            $this->updateIrrigationStatus($event->irrigation, 'in-progress', 'opened');
        } else if ($eventType === 'finished') {
            $this->updateIrrigationStatus($event->irrigation, 'completed', 'closed');
        }
    }

    /**
     * Update the irrigation status and valves status.
     *
     * @param \App\Models\Irrigation $irrigation
     * @param string $newStatus
     * @param string $valveStatus
     */
    private function updateIrrigationStatus($irrigation, $newStatus, $valveStatus)
    {
        $irrigation->update(['status' => $newStatus]);

        $irrigation->creator->notify(new IrrigationNotification($irrigation));

        foreach ($irrigation->valves as $valve) {
            $pivotData = [
                'status' => $valveStatus,
                $valveStatus === 'opened' ? 'opened_at' : 'closed_at' => now(),
            ];

            if ($valveStatus === 'closed') {
                $pivotData['duration'] = $irrigation->start_time->diffInMinutes(now());
            }

            $irrigation->valves()->updateExistingPivot($valve->id, $pivotData);

            $valve->is_open = $valveStatus === 'opened';
            $valve->save();
            $valve->pump->update(['is_active' => $valveStatus === 'opened']);
        }
    }
}
