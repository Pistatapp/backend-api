<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UniqueId
{
    public static function generate(): array
    {
        return [
            'unique_id' => Str::random(15),
        ];
    }

    /**
     * Generate a unique_id that is not already stored on the given table.
     */
    public static function makeForTable(string $table): array
    {
        do {
            $uniqueId = Str::random(15);
        } while (DB::table($table)->where('unique_id', $uniqueId)->exists());

        return [
            'unique_id' => $uniqueId,
        ];
    }

    /**
     * Generate distinct unique_ids for a batch insert (rows not yet persisted).
     *
     * @return list<array{unique_id: string}>
     */
    public static function reserveForBatch(string $table, int $count): array
    {
        $seen = [];
        $pairs = [];

        for ($i = 0; $i < $count; $i++) {
            do {
                $pair = self::generate();
            } while (
                isset($seen[$pair['unique_id']])
                || DB::table($table)->where('unique_id', $pair['unique_id'])->exists()
            );

            $seen[$pair['unique_id']] = true;
            $pairs[] = $pair;
        }

        return $pairs;
    }
}
