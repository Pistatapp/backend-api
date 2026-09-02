<?php

namespace Tests\Unit\Jobs;

use App\Jobs\IngestGpsData;
use Tests\TestCase;

class GpsIngestPolicyTest extends TestCase
{
    public function test_device_timestamp_is_never_replaced_by_server_time(): void
    {
        $job = new IngestGpsData([]);
        $method = new \ReflectionMethod($job, 'normalizeDeviceDateTime');
        $method->setAccessible(true);

        $this->assertSame('2004-01-01 03:30:24', $method->invoke($job, '2004-01-01 03:30:24', '868064071065855', 0));
        $this->assertSame('2068-11-30 07:49:21', $method->invoke($job, '2068-11-30 07:49:21', '868064071065855', 1));
        $this->assertNull($method->invoke($job, 'not-a-date', '868064071065855', 2));
    }

    public function test_exact_replay_dedupes_but_same_timestamp_different_coordinate_is_retained(): void
    {
        $job = new IngestGpsData([]);
        $method = new \ReflectionMethod($job, 'deduplicateIncomingChunk');
        $method->setAccessible(true);
        $frame = [
            'imei' => '868064071065855',
            'date_time' => '2026-08-31 10:00:00',
            'coordinate' => '[35.940486,50.059037]',
        ];

        $result = $method->invoke($job, [
            $frame,
            $frame,
            array_merge($frame, ['coordinate' => '[35.940500,50.059100]']),
        ]);

        $this->assertCount(2, $result);
        $this->assertSame('[35.940500,50.059100]', $result[1]['coordinate']);
    }
}
