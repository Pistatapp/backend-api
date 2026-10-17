<?php

namespace Tests\Unit\Services;

use App\Services\HistoricalPathCursor;
use InvalidArgumentException;
use Tests\TestCase;

class HistoricalPathCursorTest extends TestCase
{
    public function test_cursor_round_trips_a_snapshot_boundary(): void
    {
        config(['app.key' => 'base64:test-gps-cursor-key']);
        $state = [
            'tractor_id' => 285,
            'date' => '2026-10-15',
            'high_water_id' => 100000,
            'after' => ['date_time' => '2026-10-15 12:00:00', 'batch_order' => 2147483647, 'id' => 50000],
            'last_segment_id' => 3,
            'started' => true,
        ];

        $this->assertSame($state, array_diff_key(HistoricalPathCursor::decode(HistoricalPathCursor::encode($state)), ['version' => true]));
    }

    public function test_tampered_cursor_is_rejected(): void
    {
        config(['app.key' => 'base64:test-gps-cursor-key']);
        $cursor = HistoricalPathCursor::encode(['tractor_id' => 285, 'date' => '2026-10-15']);
        $parts = explode('.', $cursor);
        $parts[1] = $parts[1].'x';

        $this->expectException(InvalidArgumentException::class);
        HistoricalPathCursor::decode(implode('.', $parts));
    }
}
