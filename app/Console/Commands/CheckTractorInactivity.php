<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TractorInactivityWarningService;

class CheckTractorInactivity extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tractors:check-inactivity';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for inactive tractors and send notifications';

    /**
     * The tractor inactivity warning service.
     */
    protected TractorInactivityWarningService $service;

    /**
     * Create a new command instance.
     */
    public function __construct(TractorInactivityWarningService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Checking for inactive tractors...');

        try {
            $this->service->checkAndNotify();
            $this->info('Tractor inactivity check completed successfully.');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error checking tractor inactivity: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
