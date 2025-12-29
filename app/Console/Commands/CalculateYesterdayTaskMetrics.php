<?php

namespace App\Console\Commands;

use App\Jobs\CalculateTaskGpsMetricsJob;
use App\Models\TractorTask;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CalculateYesterdayTaskMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tractor:calculate-yesterday-metrics';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate GPS metrics for all tractor tasks from yesterday';

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
        $this->info('Calculating GPS metrics for yesterday\'s tractor tasks...');

        try {
            $yesterday = Carbon::yesterday()->toDateString();

            $this->info("Processing tasks from: {$yesterday}");

            $tasksCount = 0;

            // Query tasks for yesterday and dispatch jobs
            TractorTask::whereDate('date', $yesterday)
                ->chunk(100, function ($tasks) use (&$tasksCount) {
                    foreach ($tasks as $task) {
                        CalculateTaskGpsMetricsJob::dispatch($task);
                        $tasksCount++;
                    }
                });

            if ($tasksCount > 0) {
                $this->info("Successfully dispatched {$tasksCount} job(s) to calculate metrics.");
            } else {
                $this->warn("No tasks found for {$yesterday}.");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error calculating yesterday\'s task metrics: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}

