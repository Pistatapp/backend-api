<?php

namespace App\Console\Commands;

use App\Jobs\CalculateTaskGpsMetricsJob;
use App\Models\TractorTask;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CalculateTodayTaskGpsMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tractor-tasks:calculate-today-gps-metrics';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate GPS metrics for all tractor tasks defined for today';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $today = Carbon::today();

        $this->info("Fetching tractor tasks for today ({$today->toDateString()})...");

        $tasks = TractorTask::forDate($today)->get();

        if ($tasks->isEmpty()) {
            $this->info('No tractor tasks found for today.');
            return Command::SUCCESS;
        }

        $this->info("Found {$tasks->count()} tractor task(s). Dispatching jobs...");

        $dispatched = 0;
        foreach ($tasks as $task) {
            CalculateTaskGpsMetricsJob::dispatch($task)->delay(Carbon::now()->addSeconds($dispatched * 10));
            $dispatched++;
        }

        $this->info("Successfully dispatched {$dispatched} job(s).");

        return Command::SUCCESS;
    }
}
