<?php

namespace App\Jobs;

use App\Models\Tractor;
use Carbon\Carbon;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Dispatcher job that creates individual tractor efficiency calculation jobs.
 * This job is lightweight and dispatches work to multiple parallel jobs.
 */
class CalculateTractorEfficiencyChartJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The date to calculate efficiency for (optional, defaults to yesterday).
     *
     * @var Carbon|null
     */
    protected $date;

    /**
     * The batch size for dispatching jobs.
     *
     * @var int
     */
    protected $batchSize;

    /**
     * Create a new job instance.
     *
     * @param Carbon|null $date Date to calculate (defaults to yesterday)
     * @param int $batchSize Number of jobs to dispatch per batch (default 100)
     */
    public function __construct(?Carbon $date = null, int $batchSize = 100)
    {
        $this->date = $date;
        $this->batchSize = $batchSize;
    }

    /**
     * Execute the job - dispatches individual tractor jobs in batches.
     */
    public function handle(): void
    {
        $date = $this->date ?? Carbon::yesterday();
        $dateString = $date->toDateString();

        Log::info('Starting tractor efficiency calculation batch dispatch', [
            'date' => $dateString,
            'batch_size' => $this->batchSize,
        ]);

        // Get all tractor IDs that need processing
        $tractorIds = Tractor::query()
            ->pluck('id')
            ->toArray();

        if (empty($tractorIds)) {
            Log::info('No tractors found for efficiency calculation');
            return;
        }

        // Split tractors into batches to avoid dispatching thousands at once
        $tractorChunks = array_chunk($tractorIds, $this->batchSize);

        Log::info('Dispatching tractor efficiency jobs', [
            'total_tractors' => count($tractorIds),
            'total_batches' => count($tractorChunks),
            'date' => $dateString,
        ]);

        // Dispatch each chunk as a separate batch
        foreach ($tractorChunks as $chunkIndex => $tractorChunk) {
            $jobs = [];

            foreach ($tractorChunk as $tractorId) {
                $jobs[] = new CalculateSingleTractorEfficiencyJob($tractorId, $date);
            }

            $batch = Bus::batch($jobs)
                ->name("Tractor Efficiency Calculation - {$dateString} - Batch " . ($chunkIndex + 1))
                ->allowFailures() // Continue even if some jobs fail
                ->onConnection('database') // Use the connection defined in queue config
                ->onQueue('efficiency') // Use dedicated efficiency queue
                ->then(function (Batch $batch) use ($dateString, $chunkIndex) {
                    // This callback runs when all jobs in the batch finish successfully
                    Log::info('Tractor efficiency batch completed', [
                        'batch_id' => $batch->id,
                        'batch_number' => $chunkIndex + 1,
                        'date' => $dateString,
                        'processed_jobs' => $batch->processedJobs(),
                        'total_jobs' => $batch->totalJobs,
                    ]);
                })
                ->catch(function (Batch $batch, \Throwable $e) use ($dateString, $chunkIndex) {
                    // This callback runs when the first job failure is detected
                    Log::error('Tractor efficiency batch encountered failures', [
                        'batch_id' => $batch->id,
                        'batch_number' => $chunkIndex + 1,
                        'date' => $dateString,
                        'error' => $e->getMessage(),
                    ]);
                })
                ->finally(function (Batch $batch) use ($dateString, $chunkIndex) {
                    // This callback runs when all jobs finish (success or failure)
                    Log::info('Tractor efficiency batch finished', [
                        'batch_id' => $batch->id,
                        'batch_number' => $chunkIndex + 1,
                        'date' => $dateString,
                        'total_jobs' => $batch->totalJobs,
                        'processed_jobs' => $batch->processedJobs(),
                        'failed_jobs' => $batch->failedJobs,
                        'pending_jobs' => $batch->pendingJobs,
                        'progress' => $batch->progress(),
                    ]);
                })
                ->dispatch();

            Log::info('Dispatched efficiency batch', [
                'batch_id' => $batch->id,
                'batch_number' => $chunkIndex + 1,
                'jobs_count' => count($jobs),
                'date' => $dateString,
            ]);

            // Free memory
            unset($jobs);
        }

        Log::info('Completed dispatching all tractor efficiency batches', [
            'date' => $dateString,
            'total_tractors' => count($tractorIds),
            'total_batches' => count($tractorChunks),
        ]);
    }
}
