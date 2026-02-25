<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StoreGpsData implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public array $backoff = [1, 3, 5, 10, 20];

    /**
     * Batch size for INSERT operations.
     */
    private const BATCH_SIZE = 500;

    public function __construct(
        public array $data,
        public int $tractorId,
    ) {
        $this->onQueue('gps-storage');
    }

    /**
     * Handle the job execution.
     *
     * @return void
     */
    public function handle(): void
    {
        $batches = array_chunk($this->data, self::BATCH_SIZE);

        Log::info('Batches', ['batches' => $batches]);

        foreach ($batches as $batch) {
            $records = $this->prepareBatch($batch);
            DB::transaction(function () use ($records) {
                DB::table('gps_data')->insert($records);
            }, 3);
        }
    }

    /**
     * Prepare the batch for insertion.
     *
     * @param array $batch The batch of data to prepare.
     * @return array The prepared batch.
     */
    private function prepareBatch(array $batch): array
    {
        return array_map(function ($item): array {
            $item = is_array($item) ? $item : json_decode($item, true);
            $record = array_merge($item, [
                'tractor_id' => $this->tractorId,
            ]);
            // Ensure coordinate and directions are stored as JSON strings for string columns
            if (isset($record['coordinate']) && is_array($record['coordinate'])) {
                $record['coordinate'] = json_encode($record['coordinate']);
            }
            if (isset($record['directions']) && (is_array($record['directions']) || is_object($record['directions']))) {
                $record['directions'] = json_encode($record['directions']);
            }
            return $record;
        }, $batch);
    }

    /**
     * Handle the job failure.
     *
     * @param \Throwable $exception The exception that caused the failure.
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('StoreGpsData failed', [
            'tractor_id' => $this->tractorId,
            'record' => $this->data,
            'error' => $exception->getMessage(),
        ]);
    }
}
