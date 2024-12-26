<?php

namespace App\Console\Commands;

use App\Events\IrrigationCompleted;
use App\Events\IrrigationStarted;
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
    protected $description = 'Change the status of irrigation from pending to in-progress or completed';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->updateIrrigationStatus('pending', 'in-progress');
        $this->updateIrrigationStatus('in-progress', 'completed');

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
            ->whereDate('date', today())
            ->where($currentStatus === 'pending' ? 'start_time' : 'end_time', '<=', now())
            ->with(['valves.pump', 'creator', 'fields'])
            ->chunk(100, function ($irrigations) use ($newStatus) {
                foreach ($irrigations as $irrigation) {
                    if ($newStatus === 'in-progress') {
                        IrrigationStarted::dispatch($irrigation, $newStatus);
                    } else {
                        IrrigationCompleted::dispatch($irrigation, $newStatus);
                    }
                }
            });
    }
}
