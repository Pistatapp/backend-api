<?php

namespace App\Console\Commands;

use App\Models\FarmPlan;
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
    protected $description = 'Change the status of the farm plan from active to inactive and vice versa.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        FarmPlan::where('status', '!=', 'expired')
            ->chunkById(100, function ($plans) {
                foreach ($plans as $plan) {
                    $newStatus = 'pending';
                    if ($plan->start_date <= now() && $plan->end_date > now()) {
                        $newStatus = 'active';
                    } elseif ($plan->end_date < now()) {
                        $newStatus = 'expired';
                    }
                    if ($plan->status !== $newStatus) {
                        $plan->update(['status' => $newStatus]);
                    }
                }
            });
    }
}
