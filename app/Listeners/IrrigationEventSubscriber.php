<?php

namespace App\Listeners;

use App\Events\IrrigationStarted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use App\Notifications\IrrigationNotification;
use Illuminate\Events\Dispatcher;
use App\Events\IrrigationCompleted;

class IrrigationEventSubscriber
{
    /**
     * Handle irrigation started events.
     */
    public function handleIrrigationStarted(IrrigationStarted $event): void
    {
        $this->updateIrrigationStatus($event->irrigation, 'in-progress', 'opened');
    }

    /**
     * Handle irrigation completed events.
     */
    public function handleIrrigationCompleted(IrrigationCompleted $event): void
    {
        $this->updateIrrigationStatus($event->irrigation, 'completed', 'closed');
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

        $irrigation->creator->notify(new IrrigationNotification($newStatus));

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
        }
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @param \Illuminate\Events\Dispatcher $events
     * @return array<int, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            IrrigationStarted::class => 'handleIrrigationStarted',
            IrrigationCompleted::class => 'handleIrrigationCompleted',
        ];
    }
}
