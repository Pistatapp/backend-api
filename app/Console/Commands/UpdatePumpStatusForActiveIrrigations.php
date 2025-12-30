<?php

namespace App\Console\Commands;

use App\Models\Irrigation;
use App\Models\Pump;
use Carbon\Carbon;
use Illuminate\Console\Command;

class UpdatePumpStatusForActiveIrrigations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pump:update-status-for-active-irrigations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update pump status based on active irrigations (current time between start and end time)';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Updating pump status for active irrigations...');

        try {
            $now = Carbon::now();

            // Find all irrigations where current time is between start_time and end_time
            $activeIrrigations = Irrigation::whereNotNull('pump_id')
                ->where('start_time', '<=', $now)
                ->where('end_time', '>=', $now)
                ->get();

            // Get unique pump IDs from active irrigations
            $pumpIdsWithActiveIrrigations = $activeIrrigations
                ->pluck('pump_id')
                ->unique()
                ->filter()
                ->values()
                ->toArray();

            $updatedCount = 0;

            if (!empty($pumpIdsWithActiveIrrigations)) {
                // Update pumps with active irrigations to active
                $updatedCount = Pump::whereIn('id', $pumpIdsWithActiveIrrigations)
                    ->where('is_active', false)
                    ->update(['is_active' => true]);

                $this->info("Activated {$updatedCount} pump(s) with active irrigations.");
            } else {
                $this->info('No active irrigations found.');
            }

            // Deactivate pumps that are currently active but don't have active irrigations
            $deactivatedCount = Pump::whereNotIn('id', $pumpIdsWithActiveIrrigations)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            if ($deactivatedCount > 0) {
                $this->info("Deactivated {$deactivatedCount} pump(s) without active irrigations.");
            }

            $this->info('Pump status update completed successfully.');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error updating pump status: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}

