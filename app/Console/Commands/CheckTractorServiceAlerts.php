<?php

namespace App\Console\Commands;

use App\Jobs\CheckTractorServiceAlertsJob;
use Illuminate\Console\Command;

class CheckTractorServiceAlerts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tractor:check-service-alerts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check tractor periodic service thresholds and send alerts';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            CheckTractorServiceAlertsJob::dispatch();
            $this->info('Tractor service alerts check completed successfully.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Error checking tractor service alerts: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
