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
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Checking for ended tractor tasks for current day...');

        try {
            $now = Carbon::now();
            $today = $now->toDateString();

            TractorTask::query()
                ->where('date', $today)
                ->whereIn('status', ['in_progress', 'stopped'])
                ->where('end_time', '<=', $now->format('H:i:s'))
                ->chunk(100, function ($tasks) use ($now) {
                    foreach ($tasks as $task) {
                        if (! $now->greaterThan($task->getEndDateTime())) {
                            continue;
                        }

                        $task->update(['status' => 'done']);
                        CalculateTaskGpsMetricsJob::dispatch($task);
                    }
                });

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error checking ended tractor tasks: '.$e->getMessage());
            $this->error($e->getTraceAsString());

            return Command::FAILURE;
        }
    }
}
