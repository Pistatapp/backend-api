<?php

namespace App\Console\Commands;

use App\Services\TractorDailyStartWarningService;
use Illuminate\Console\Command;

class CheckTractorDailyStart extends Command
{
    protected $signature = 'tractors:check-daily-start';

    protected $description = 'Alert farm managers when assigned GPS tractors have not started by 09:00';

    public function __construct(private TractorDailyStartWarningService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $sent = $this->service->checkAndNotify();
        $this->info("Daily tractor-start check completed; notifications sent: {$sent}.");

        return self::SUCCESS;
    }
}
