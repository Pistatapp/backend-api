<?php

namespace App\Console\Commands;

use App\Models\Tractor;
use Illuminate\Console\Command;

class ChangeTractorActivityStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tractor:check-activity-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command will change the tractor activity status based on the last activity time';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Tractor::where('last_activity', '<', now()->subMinutes(5))
            ->update(['is_working' => false]);
    }
}
