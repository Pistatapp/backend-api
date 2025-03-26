<?php

namespace App\Console\Commands;

use App\Models\FarmPlan;
use App\Events\FarmPlanStatusChanged;
use Illuminate\Console\Command;

class ChangeFarmPlanStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:change-farm-plan-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Change the status of the farm plan from pending to started and vice versa.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        FarmPlan::where('status', '!=', 'finished')
            ->chunkById(100, function ($plans) {
                foreach ($plans as $plan) {
                    $newStatus = 'pending';
                    if ($plan->start_date <= now() && $plan->end_date > now()) {
                        $newStatus = 'started';
                    } elseif ($plan->end_date < now()) {
                        $newStatus = 'finished';
                    }
                    if ($plan->status !== $newStatus) {
                        $plan->update(['status' => $newStatus]);
                        FarmPlanStatusChanged::dispatch($plan, $newStatus);
                    }
                }
            });
    }
}
