<?php

namespace Tests\Unit\Support;

use App\Support\GpsDeviceCache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class GpsDeviceCacheTest extends TestCase
{
    public function test_json_key_format(): void
    {
        $this->assertSame('gps:device:863070046120282', GpsDeviceCache::jsonKey('863070046120282'));
    }

    public function test_json_payload_structure(): void
    {
        $this->assertSame([
            'tractor_id' => 42,
            'device_id' => 7,
        ], GpsDeviceCache::jsonPayload(42, 7));
    }

    public function test_put_writes_snake_case_json_for_go_service(): void
    {
        Redis::shouldReceive('setex')
            ->once()
            ->with(
                'gps:device:863070046120282',
                3600,
                '{"tractor_id":42,"device_id":7}'
            );

        GpsDeviceCache::put('863070046120282', 42, 7);
    }
}
