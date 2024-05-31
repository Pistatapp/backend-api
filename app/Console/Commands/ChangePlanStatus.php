<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Plan;

class ChangePlanStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:change-plan-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Change the status of the plan from active to inactive and vice versa.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Plan::chunkById(100, function ($plans) {
            foreach ($plans as $plan) {
                if ($plan->start_date <= now() && $plan->end_date > now()) {
                    $plan->update(['status' => 'active']);
                } elseif ($plan->end_date < now()) {
                    $plan->update(['status' => 'expired']);
                } else {
                    $plan->update(['status' => 'pending']);
                }
            }
        });
    }
}
