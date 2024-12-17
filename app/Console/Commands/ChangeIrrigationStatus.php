<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Irrigation;
use App\Notifications\IrrigationNotification;

class ChangeIrrigationStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:change-irrigation-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Change the status of irrigation from pending to in-progress or completed';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->updateIrrigationStatus('pending', 'in-progress', 'opened');
        $this->updateIrrigationStatus('in-progress', 'completed', 'closed');

        return Command::SUCCESS;
    }

    /**
     * Update the irrigation status
     *
     * @param string $currentStatus
     * @param string $newStatus
     * @param string $valveStatus
     */
    private function updateIrrigationStatus($currentStatus, $newStatus, $valveStatus)
    {
        Irrigation::where('status', $currentStatus)
            ->whereDate('date', today())
            ->where($currentStatus === 'pending' ? 'start_time' : 'end_time', '<=', now())
            ->with('valves')
            ->chunk(100, function ($irrigations) use ($newStatus, $valveStatus) {
                foreach ($irrigations as $irrigation) {
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
            });
    }
}
