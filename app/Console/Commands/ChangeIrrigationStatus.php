<?php

namespace App\Console\Commands;

use App\Events\IrrigationEvent;
use Illuminate\Console\Command;
use App\Models\Irrigation;

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
    protected $description = 'Change the status of irrigation from pending to in-progress or finished';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->updateIrrigationStatus('pending', 'in-progress');
        $this->updateIrrigationStatus('in-progress', 'finished');

        return Command::SUCCESS;
    }

    /**
     * Update the irrigation status.
     *
     * @param string $currentStatus
     * @param string $newStatus
     */
    private function updateIrrigationStatus($currentStatus, $newStatus)
    {
        Irrigation::where('status', $currentStatus)
            ->where(function ($query) {
                $today = today();
                $query->whereDate('start_date', '<=', $today)
                    ->where(function ($q) use ($today) {
                        $q->whereDate('end_date', '>=', $today)
                            ->orWhereNull('end_date')
                            ->whereDate('start_date', $today);
                    });
            })
            ->where($currentStatus === 'pending' ? 'start_time' : 'end_time', '<=', now())
            ->with(['valves', 'creator', 'plots'])
            ->chunk(100, function ($irrigations) use ($newStatus) {
                foreach ($irrigations as $irrigation) {
                    // Use the merged IrrigationEvent with the appropriate eventType
                    $eventType = $newStatus === 'in-progress' ? 'started' : 'finished';
                    IrrigationEvent::dispatch($irrigation, $newStatus, $eventType);
                }
            });
    }
}
