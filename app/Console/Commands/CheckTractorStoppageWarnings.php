<?php

namespace App\Console\Commands;

use App\Services\TractorStoppageWarningService;
use Illuminate\Console\Command;

class CheckTractorStoppageWarnings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tractor:check-stoppage-warnings';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for tractor stoppage warnings and send notifications if needed';

    /**
     * Create a new command instance.
     */
    public function __construct(
        private TractorStoppageWarningService $warningService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Checking for tractor stoppage warnings...');

        try {
            $this->warningService->checkAndNotify();
            $this->info('Tractor stoppage warning check completed successfully.');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error checking tractor stoppage warnings: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Get the command output for testing purposes.
     */
    public function getOutput()
    {
        return $this->output;
    }
}
