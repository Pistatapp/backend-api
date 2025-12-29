<?php

namespace App\Console\Commands;

use App\Jobs\CalculateGpsMetricsJob;
use App\Models\Tractor;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CalculateYesterdayGpsMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tractor:calculate-yesterday-gps-metrics';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate GPS metrics for all tractors from yesterday';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Calculating GPS metrics for yesterday\'s tractors...');

        try {
            $yesterday = Carbon::yesterday();

            $this->info("Processing tractors for date: {$yesterday->toDateString()}");

            $tractorsCount = 0;

            // Query all tractors and dispatch jobs
            Tractor::chunk(100, function ($tractors) use ($yesterday, &$tractorsCount) {
                foreach ($tractors as $tractor) {
                    CalculateGpsMetricsJob::dispatch($tractor, $yesterday);
                    $tractorsCount++;
                }
            });

            if ($tractorsCount > 0) {
                $this->info("Successfully dispatched {$tractorsCount} job(s) to calculate GPS metrics.");
            } else {
                $this->warn("No tractors found.");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error calculating yesterday\'s GPS metrics: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}

