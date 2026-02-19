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

    public function handle(): void
    {
        if (empty($this->data)) {
            return;
        }

        $batchSize = 500;
        $batches = array_chunk($this->data, $batchSize);

        foreach ($batches as $batch) {
            $records = $this->prepareBatch($batch);

            DB::table('gps_data')->insert($records);
        }
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
