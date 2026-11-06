<?php

namespace Tests\Unit\Support;

use App\Support\GpsTimestamp;
use PHPUnit\Framework\TestCase;

class GpsTimestampTest extends TestCase
{
    public function test_explicit_utc_is_preserved(): void
    {
        $this->assertSame('2026-11-02 17:03:23', GpsTimestamp::normalizeToUtc('2026-11-02T17:03:23Z'));
    }

    public function test_explicit_numeric_offset_is_normalized_once(): void
    {
        $this->assertSame('2026-11-02 17:03:23', GpsTimestamp::normalizeToUtc('2026-11-02T20:33:23+03:30'));
    }

    public function test_legacy_naive_tehran_value_is_normalized_to_utc(): void
    {
        $this->assertSame('2026-11-02 17:03:23', GpsTimestamp::normalizeToUtc('2026-11-02 20:33:23', 'Asia/Tehran'));
    }

    public function test_invalid_value_is_rejected_without_receipt_substitution(): void
    {
        $this->assertNull(GpsTimestamp::normalizeToUtc('not-a-timestamp'));
    }
}
