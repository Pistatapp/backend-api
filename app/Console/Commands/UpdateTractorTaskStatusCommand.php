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

        TractorTask::whereDate('date', $now->toDateString())
            ->where(function ($query) use ($now) {
                $query->where(function ($q) use ($now) {
                    $q->where('status', 'pending')
                        ->where('start_time', '<=', $now->format('H:i'));
                })->orWhere(function ($q) use ($now) {
                    $q->where('status', 'started')
                        ->where('end_time', '<=', $now->format('H:i'));
                });
            })
            ->chunk(100, function ($tasks) {
                foreach ($tasks as $task) {
                    $newStatus = $task->status === 'pending' ? 'started' : 'finished';
                    $task->update(['status' => $newStatus]);
                    event(new TractorTaskStatusChanged($task, $newStatus));
                }
            });
    }
}
