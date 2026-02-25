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
        if (empty($this->data)) {
            return;
        }

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
        $prepared = [];

        foreach ($batch as $item) {
            $coordinate = $item['coordinate'];
            $directions = $item['directions'];

            if (is_array($coordinate)) {
                $coordinate = json_encode($coordinate, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } elseif (is_string($coordinate)) {
                $decoded = json_decode($coordinate, true);
                if (is_array($decoded)) {
                    $coordinate = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                }
            }

            if (is_array($directions)) {
                $directions = json_encode($directions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } elseif (is_string($directions)) {
                $decoded = json_decode($directions, true);
                if (is_array($decoded)) {
                    $directions = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                }
            }

            $prepared[] = [
                'tractor_id' => (int) $this->tractorId,
                'coordinate' => (string) $coordinate,
                'speed' => (int) ($item['speed'] ?? 0),
                'status' => (int) ($item['status'] ?? 0),
                'directions' => (string) $directions,
                'imei' => (string) ($item['imei'] ?? ''),
                'date_time' => $item['date_time'] ?? now()->format('Y-m-d H:i:s'),
            ];
        }

        return $prepared;
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
