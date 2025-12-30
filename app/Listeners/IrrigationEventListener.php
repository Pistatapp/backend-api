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
            $this->updateIrrigationStatus($event->irrigation, 'finished', 'closed');
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
        $irrigation->loadMissing('creator', 'pump', 'valves');

        $this->updateIrrigationModelStatus($irrigation, $newStatus);
        $this->updatePumpStatusIfNeeded($irrigation, $newStatus);
        $this->notifyCreatorIfExists($irrigation);
        $this->updateAllValvesStatus($irrigation, $valveStatus);
    }

    private function updateIrrigationModelStatus($irrigation, $newStatus)
    {
        $irrigation->update(['status' => $newStatus]);
    }

    private function updatePumpStatusIfNeeded($irrigation, $newStatus)
    {
        if (!$irrigation->pump) {
            return;
        }

        if ($newStatus === 'in-progress') {
            $irrigation->pump->update(['is_active' => 1]);
        } elseif ($newStatus === 'finished') {
            $this->deactivatePumpIfNoActiveIrrigations($irrigation);
        }
    }

    private function deactivatePumpIfNoActiveIrrigations($irrigation)
    {
        $now = now();
        $activeIrrigationsCount = $irrigation->pump->irrigations()
            ->where('id', '!=', $irrigation->id)
            ->where('status', 'in-progress')
            ->count();

        if ($activeIrrigationsCount === 0) {
            $irrigation->pump->update(['is_active' => 0]);
        }
    }

    private function notifyCreatorIfExists($irrigation)
    {
        if ($irrigation->creator) {
            $irrigation->creator->notify(new IrrigationNotification($irrigation));
        }
    }

    private function updateAllValvesStatus($irrigation, $valveStatus)
    {
        foreach ($irrigation->valves as $valve) {
            $this->updateValveStatus($irrigation, $valve, $valveStatus);
        }
    }

    private function updateValveStatus($irrigation, $valve, $valveStatus)
    {
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
