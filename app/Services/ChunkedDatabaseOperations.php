<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class ChunkedDatabaseOperations
{
    private const CHUNK_SIZE = 1000;

    public function processLargeDataset(Collection $data, callable $processor): void
    {
        $data->chunk(self::CHUNK_SIZE)->each(function ($chunk) use ($processor) {
            DB::transaction(function () use ($chunk, $processor) {
                $processor($chunk);
            });
        });
    }

    public function batchInsert(string $table, array $data): void
    {
        $chunks = array_chunk($data, self::CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}
