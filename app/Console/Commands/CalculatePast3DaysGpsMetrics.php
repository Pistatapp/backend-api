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

        $totalTractors = Tractor::count();
        $totalExpectedJobs = $totalTractors * count($dates);

        $this->info("Found {$totalTractors} tractor(s). Expected {$totalExpectedJobs} job(s) to dispatch.");

        $totalDispatched = 0;
        $failedDispatches = 0;
        $errors = [];
        $tractorIndex = 0;

        $progressBar = $this->output->createProgressBar($totalExpectedJobs);
        $progressBar->start();

        // Use chunking to handle large datasets and avoid memory issues
        Tractor::chunk(100, function ($tractors) use ($dates, &$totalDispatched, &$failedDispatches, &$errors, &$tractorIndex, $progressBar) {
            foreach ($tractors as $tractor) {
                // Stagger by tractor (5 seconds between tractors)
                // Dates for the same tractor are spaced 1 second apart
                $baseDelay = ($tractorIndex * 5) + 5;

                foreach ($dates as $dateIndex => $date) {
                    try {
                        // Add 1 second offset between dates for the same tractor
                        $delay = $baseDelay + $dateIndex;
                        CalculateGpsMetricsJob::dispatch($tractor, $date)
                            ->delay(now()->addSeconds($delay));
                        $totalDispatched++;
                    } catch (\Exception $e) {
                        $failedDispatches++;
                        $errors[] = sprintf(
                            'Tractor ID %d, Date %s: %s',
                            $tractor->id,
                            $date->toDateString(),
                            $e->getMessage()
                        );
                        $this->newLine();
                        $this->error("Failed to dispatch job for Tractor ID {$tractor->id}, Date {$date->toDateString()}: {$e->getMessage()}");
                    }
                    $progressBar->advance();
                }
                $tractorIndex++;
            }
        });

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Successfully dispatched {$totalDispatched} job(s).");

        if ($failedDispatches > 0) {
            $this->error("Failed to dispatch {$failedDispatches} job(s).");
            if ($this->getOutput()->isVerbose()) {
                foreach ($errors as $error) {
                    $this->line("  - {$error}");
                }
            }
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
