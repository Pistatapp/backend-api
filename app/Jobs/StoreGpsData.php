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
        $records = $this->prepareBatch($this->data);
        Log::info('StoreGpsData: Records', ['records' => $records]);
        // DB::connection('mysql_gps')->transaction(function () use ($records) {
        //     DB::table('gps_data')->insert($records);
        // }, 3);
    }

    /**
     * Prepare the batch for insertion.
     *
     * @param array $batch The batch of data to prepare.
     * @return array The prepared batch.
     */
    private function prepareBatch(array $data): array
    {
        return array_map(function (array $item) {
            return array_merge($item, [
                'tractor_id' => $this->tractorId,
                'coordinate' => json_encode($item['coordinate']),
                'speed' => $item['speed'],
                'status' => $item['status'],
                'directions' => json_encode($item['directions']),
                'imei' => $item['imei'],
                'date_time' => $item['date_time'],
            ]);
        }, $data);
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
