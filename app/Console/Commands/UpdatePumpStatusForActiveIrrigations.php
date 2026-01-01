<?php

namespace App\Console\Commands;

use App\Models\Irrigation;
use App\Models\Pump;
use Illuminate\Console\Command;

class UpdatePumpStatusForActiveIrrigations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-pump-status-active-irrigations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the status of all pumps that have active irrigations (current time falls between start_time and end_time)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = now();
        $updatedCount = 0;
        $deactivatedCount = 0;

        // Find all active irrigations (current time is between start_time and end_time)
        $activeIrrigations = Irrigation::where('start_time', '<=', $now)
            ->where('end_time', '>=', $now)
            ->whereNotNull('pump_id')
            ->with('pump')
            ->get();

        // Get unique pump IDs from active irrigations
        $pumpIdsWithActiveIrrigations = $activeIrrigations
            ->pluck('pump_id')
            ->unique()
            ->filter()
            ->values();

        if ($pumpIdsWithActiveIrrigations->isEmpty()) {
            $this->info('No active irrigations found. Checking for pumps to deactivate...');
        } else {
            $this->info("Found {$activeIrrigations->count()} active irrigation(s) for " . $pumpIdsWithActiveIrrigations->count() . " pump(s).");

            // Update pumps with active irrigations to is_active = true
            Pump::whereIn('id', $pumpIdsWithActiveIrrigations)
                ->chunk(100, function ($pumps) use (&$updatedCount) {
                    foreach ($pumps as $pump) {
                        if (!$pump->is_active) {
                            $pump->update(['is_active' => true]);
                            $updatedCount++;
                            $this->line("Activated pump: {$pump->name} (ID: {$pump->id})");
                        }
                    }
                });
        }

        // Also deactivate pumps that don't have active irrigations but are currently active
        // This ensures data consistency
        Pump::where('is_active', true)
            ->whereNotIn('id', $pumpIdsWithActiveIrrigations)
            ->chunk(100, function ($pumps) use ($now, &$deactivatedCount) {
                foreach ($pumps as $pump) {
                    // Double-check: verify this pump has no active irrigations
                    $hasActiveIrrigation = Irrigation::where('pump_id', $pump->id)
                        ->where('start_time', '<=', $now)
                        ->where('end_time', '>=', $now)
                        ->exists();

                    if (!$hasActiveIrrigation) {
                        $pump->update(['is_active' => false]);
                        $deactivatedCount++;
                        $this->line("Deactivated pump: {$pump->name} (ID: {$pump->id})");
                    }
                }
            });

        $this->info("Command completed. Activated: {$updatedCount}, Deactivated: {$deactivatedCount}");

        return Command::SUCCESS;
    }
}

