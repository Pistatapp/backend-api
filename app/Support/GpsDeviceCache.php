<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;

class GpsDeviceCache
{
    public const JSON_KEY_PREFIX = 'gps:device:';

    public const TTL_SECONDS = 3600;

    public static function jsonKey(string $imei): string
    {
        return self::JSON_KEY_PREFIX.$imei;
    }

    /**
     * @return array{tractor_id: int, device_id: int}
     */
    public static function jsonPayload(int $tractorId, int $deviceId): array
    {
        return [
            'tractor_id' => $tractorId,
            'device_id' => $deviceId,
        ];
    }

    public static function put(string $imei, int $tractorId, int $deviceId): void
    {
        Redis::setex(
            self::jsonKey($imei),
            self::TTL_SECONDS,
            json_encode(self::jsonPayload($tractorId, $deviceId), JSON_THROW_ON_ERROR)
        );
    }
}
