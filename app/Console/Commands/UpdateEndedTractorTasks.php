<?php

namespace App\Console\Commands;

use App\Jobs\CalculateTaskGpsMetricsJob;
use App\Models\TractorTask;
use Carbon\Carbon;
use Illuminate\Console\Command;

class UpdateEndedTractorTasks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tractor:update-ended-tasks';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check if any tractor tasks end time has passed and update their status';

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
        $this->info('Checking for ended tractor tasks for current day...');

        try {
            $now = Carbon::now();
            $today = $now->toDateString();

            // Query tasks for current day where end time has passed
            TractorTask::whereDate('date', $today)
                ->whereRaw('CONCAT(date, " ", end_time) <= ?', [$now->format('Y-m-d H:i:s')])
                ->chunk(100, function ($tasks) {
                    foreach ($tasks as $task) {
                        // Dispatch job to calculate metrics and update status
                        CalculateTaskGpsMetricsJob::dispatch($task);
                    }
                });

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error checking ended tractor tasks: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}
