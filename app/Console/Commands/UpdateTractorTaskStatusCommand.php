<?php

namespace App\Console\Commands;

use App\Events\TractorTaskStatusChanged;
use App\Models\TractorTask;
use Carbon\Carbon;
use Illuminate\Console\Command;

class UpdateTractorTaskStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tractor:update-task-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check tractor tasks and update their status based on time';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $now = Carbon::now();

        // Find tasks that should start now (pending tasks where start time has passed)
        $startingTasks = TractorTask::where('status', 'pending')
            ->whereDate('date', $now->toDateString())
            ->where('start_time', '<=', $now->format('H:i'))
            ->get();

        foreach ($startingTasks as $task) {
            $task->update(['status' => 'started']);
            event(new TractorTaskStatusChanged($task, 'started'));
            $this->info("Task {$task->id} status updated to started");
        }

        // Find tasks that should end now (started tasks where end time has passed)
        $endingTasks = TractorTask::where('status', 'started')
            ->whereDate('date', $now->toDateString())
            ->where('end_time', '<=', $now->format('H:i'))
            ->get();

        foreach ($endingTasks as $task) {
            $task->update(['status' => 'finished']);
            event(new TractorTaskStatusChanged($task, 'finished'));
            $this->info("Task {$task->id} status updated to finished");
        }
    }
}
