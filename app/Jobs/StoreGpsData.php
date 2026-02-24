<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DeadlockException;
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

    private const DEADLOCK_MAX_RETRIES = 3;
    private const DEADLOCK_RETRY_DELAY_MS = 50;

    public function __construct(
        public array $data,
        public int $tractorId,
    ) {
        $this->onQueue('gps-storage');
    }

    public function handle(): void
    {
        if (empty($this->data)) {
            return;
        }

        $batchSize = 100;
        $batches = array_chunk($this->data, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            $records = $this->prepareBatch($batch);
            $this->insertWithDeadlockRetry($records, $batchIndex);
        }
    }

    private function getConnection(): \Illuminate\Database\Connection
    {
        return DB::connection('mysql_gps');
    }

    private function insertWithDeadlockRetry(array $records, int $batchIndex): void
    {
        $lastException = null;
        $connection = $this->getConnection();

        for ($attempt = 1; $attempt <= self::DEADLOCK_MAX_RETRIES; $attempt++) {
            try {
                $connection->transaction(function () use ($connection, $records) {
                    $connection->table('gps_data')->insert($records);
                }, 3);

                return;
            } catch (DeadlockException $e) {
                $lastException = $e;
                Log::warning('StoreGpsData: deadlock detected, retrying', [
                    'tractor_id' => $this->tractorId,
                    'batch_index' => $batchIndex,
                    'attempt' => $attempt,
                    'record_count' => count($records),
                ]);

                if ($attempt < self::DEADLOCK_MAX_RETRIES) {
                    $jitter = random_int(0, 20);
                    usleep((self::DEADLOCK_RETRY_DELAY_MS + $jitter) * 1000 * $attempt);
                }
            } catch (\PDOException $e) {
                if ($this->isDeadlockException($e)) {
                    $lastException = $e;
                    Log::warning('StoreGpsData: PDO deadlock detected, retrying', [
                        'tractor_id' => $this->tractorId,
                        'batch_index' => $batchIndex,
                        'attempt' => $attempt,
                        'error_code' => $e->getCode(),
                    ]);

                    if ($attempt < self::DEADLOCK_MAX_RETRIES) {
                        $jitter = random_int(0, 20);
                        usleep((self::DEADLOCK_RETRY_DELAY_MS + $jitter) * 1000 * $attempt);
                    }
                    continue;
                }
                throw $e;
            }
        }

        if ($lastException !== null) {
            Log::error('StoreGpsData: failed after deadlock retries', [
                'tractor_id' => $this->tractorId,
                'batch_index' => $batchIndex,
                'record_count' => count($records),
            ]);
            throw $lastException;
        }
    }

    private function isDeadlockException(\PDOException $e): bool
    {
        $code = $e->getCode();
        $message = strtolower($e->getMessage());

        return $code === '40001'
            || $code === 1213
            || str_contains($message, 'deadlock')
            || str_contains($message, 'lock wait timeout');
    }

    private function prepareBatch(array $batch): array
    {
        $records = [];

        foreach ($batch as $item) {
            $records[] = [
                'tractor_id' => $this->tractorId,
                'coordinate' => json_encode($item['coordinate']),
                'speed' => $item['speed'],
                'status' => $item['status'],
                'directions' => json_encode($item['directions']),
                'imei' => $item['imei'],
                'date_time' => $item['date_time'],
            ];
        }

        return $records;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('StoreGpsData failed', [
            'tractor_id' => $this->tractorId,
            'record_count' => count($this->data),
            'error' => $exception->getMessage(),
        ]);
    }
}
