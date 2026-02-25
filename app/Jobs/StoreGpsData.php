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

    /**
     * Batch size for INSERT operations.
     * Larger batches = fewer transactions = less lock contention.
     * 500 is optimal for balancing memory usage and lock duration.
     */
    private const BATCH_SIZE = 500;

    /**
     * Delay between batches in milliseconds.
     * Gives read operations a chance to execute between write batches.
     */
    private const INTER_BATCH_DELAY_MS = 10;

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

        $batches = array_chunk($this->data, self::BATCH_SIZE);
        $totalBatches = count($batches);

        foreach ($batches as $batchIndex => $batch) {
            $records = $this->prepareBatch($batch);
            $this->insertWithDeadlockRetry($records, $batchIndex);

            // Add small delay between batches to reduce sustained write pressure
            // Skip delay for the last batch
            if ($batchIndex < $totalBatches - 1 && self::INTER_BATCH_DELAY_MS > 0) {
                usleep(self::INTER_BATCH_DELAY_MS * 1000);
            }
        }
    }

    private ?\Illuminate\Database\Connection $writeConnection = null;

    private function getConnection(): \Illuminate\Database\Connection
    {
        if ($this->writeConnection === null) {
            $this->writeConnection = DB::connection('mysql_gps');
            $this->optimizeWriteConnection($this->writeConnection);
        }

        return $this->writeConnection;
    }

    /**
     * Apply session-level optimizations for bulk write operations.
     */
    private function optimizeWriteConnection(\Illuminate\Database\Connection $connection): void
    {
        // Reduce lock wait timeout to fail fast and retry, rather than blocking
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 5');

        // Use READ COMMITTED for writes to reduce lock duration
        // (rows are unlocked after the statement, not after the transaction)
        $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
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
