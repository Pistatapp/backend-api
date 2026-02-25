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

        foreach ($batches as $batch) {
            $records = $this->prepareBatch($batch);
            DB::connection('mysql_gps')->transaction(function () use ($records) {
                DB::connection('mysql_gps')->table('gps_data')->insert($records);
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
        return array_map(function (array $item): array {
            return [
                'tractor_id' => $this->tractorId,
                'coordinate' => is_array($item['coordinate'])
                    ? json_encode($item['coordinate'])
                    : $item['coordinate'],
                'speed' => $item['speed'],
                'status' => $item['status'],
                'directions' => is_array($item['directions'])
                    ? json_encode($item['directions'])
                    : $item['directions'],
                'imei' => $item['imei'],
                'date_time' => $item['date_time'],
            ];
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
