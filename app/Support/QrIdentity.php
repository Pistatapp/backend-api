<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class QrIdentity
{
    /**
     * @return array{unique_id: string, qr_code: string}
     */
    public static function generate(): array
    {
        $uniqueId = Str::random(15);

        return [
            'unique_id' => $uniqueId,
            'qr_code' => base64_encode(QrCode::size(300)->generate($uniqueId)),
        ];
    }

    /**
     * Generate a unique_id that is not already stored on the given table.
     *
     * @return array{unique_id: string, qr_code: string}
     */
    public static function makeForTable(string $table): array
    {
        do {
            $uniqueId = Str::random(15);
        } while (DB::table($table)->where('unique_id', $uniqueId)->exists());

        return [
            'unique_id' => $uniqueId,
            'qr_code' => base64_encode(QrCode::size(300)->generate($uniqueId)),
        ];
    }

    /**
     * Generate distinct identities for a batch insert (rows not yet persisted).
     *
     * @return list<array{unique_id: string, qr_code: string}>
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
