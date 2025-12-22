<?php

namespace App\Console\Commands;

use App\Jobs\CalculateGpsMetricsJob;
use App\Models\Tractor;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CalculatePast3DaysGpsMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tractors:calculate-past-3-days-gps-metrics';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate GPS metrics for all tractors for the past 3 days';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $today = Carbon::today();
        $dates = [
            $today->copy()->subDay(),
            $today->copy()->subDays(2),
            $today->copy()->subDays(3),
        ];

        $this->info('Calculating GPS metrics for all tractors for the past 3 days...');
        $this->info('Dates: ' . implode(', ', array_map(fn($date) => $date->toDateString(), $dates)));

        $totalDispatched = 0;

        // Use chunking to handle large datasets and avoid memory issues
        Tractor::chunk(100, function ($tractors) use ($dates, &$totalDispatched) {
            foreach ($tractors as $tractor) {
                foreach ($dates as $date) {
                    CalculateGpsMetricsJob::dispatch($tractor, $date)->withoutOverlapping();
                    $totalDispatched++;
                }
            }
        });

        $this->info("Successfully dispatched {$totalDispatched} job(s).");

        return Command::SUCCESS;
    }
}

