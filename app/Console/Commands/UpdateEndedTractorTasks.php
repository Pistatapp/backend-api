<?php

namespace App\Console\Commands;

use App\Models\TractorTask;
use App\Services\TractorTaskStatusService;
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
    public function __construct(
        private TractorTaskStatusService $taskStatusService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Checking for ended tractor tasks...');

        try {
            $now = Carbon::now();
            $updatedCount = 0;

            // Get all tasks that haven't been finalized yet (excluding already done/not_done tasks)
            $tasks = TractorTask::whereIn('status', ['not_started', 'in_progress', 'stopped'])
                ->get();

            foreach ($tasks as $task) {
                $taskDateTime = Carbon::parse($task->date);
                $taskEndDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->end_time);

                // Handle case where end time is before start time (crosses midnight)
                $taskStartDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->start_time);
                if ($taskEndDateTime->lt($taskStartDateTime)) {
                    $taskEndDateTime->addDay();
                }

                // Check if task end time has passed
                if ($now->gte($taskEndDateTime)) {
                    $oldStatus = $task->status;
                    $this->taskStatusService->updateTaskStatus($task);
                    $task->refresh();

                    if ($oldStatus !== $task->status) {
                        $updatedCount++;
                        $this->line(sprintf(
                            'Updated task #%d (tractor_id: %d) from "%s" to "%s"',
                            $task->id,
                            $task->tractor_id,
                            $oldStatus,
                            $task->status
                        ));
                    }
                }
            }

            if ($updatedCount > 0) {
                $this->info(sprintf('Successfully updated %d task(s).', $updatedCount));
            } else {
                $this->info('No tasks needed updating.');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error updating ended tractor tasks: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}

