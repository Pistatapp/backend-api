<?php

namespace App\Support;

use Carbon\Carbon;
use Throwable;

/** Normalize GPS audit timestamps without rewriting legacy gps_data.date_time. */
final class GpsTimestamp
{
    public static function normalizeToUtc(?string $value, string $naiveTimezone = 'Asia/Tehran'): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return null;
        }

        try {
            $hasExplicitOffset = (bool) preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $value);
            $parsed = Carbon::parse($value, $hasExplicitOffset ? null : $naiveTimezone);

            return $parsed->utc()->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    public static function nowUtc(): string
    {
        return Carbon::now('UTC')->format('Y-m-d H:i:s');
    }
}
