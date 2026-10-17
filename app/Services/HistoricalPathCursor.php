<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Stateless, signed cursor for the versioned historical tractor-path API.
 *
 * The cursor contains only the immutable snapshot boundary and the last
 * ordered key. It is not an authorization token; the route policy is still
 * evaluated for every request.
 */
final class HistoricalPathCursor
{
    private const PREFIX = 'gps-path-v2';

    public static function encode(array $state): string
    {
        $payload = base64_encode((string) json_encode(
            ['version' => 2] + $state,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        $payload = rtrim(strtr($payload, '+/', '-_'), '=');
        $signature = self::sign($payload);

        return self::PREFIX.'.'.$payload.'.'.$signature;
    }

    /**
     * @return array<string,mixed>
     */
    public static function decode(string $cursor): array
    {
        $parts = explode('.', $cursor);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX || $parts[1] === '') {
            throw new InvalidArgumentException('Invalid GPS path cursor.');
        }

        $expected = self::sign($parts[1]);
        if (! hash_equals($expected, $parts[2])) {
            throw new InvalidArgumentException('Invalid GPS path cursor signature.');
        }

        $encoded = strtr($parts[1], '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $json = base64_decode($encoded, true);
        if ($json === false) {
            throw new InvalidArgumentException('Invalid GPS path cursor payload.');
        }

        try {
            $state = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('Invalid GPS path cursor payload.', 0, $exception);
        }

        if (! is_array($state) || (int) ($state['version'] ?? 0) !== 2) {
            throw new InvalidArgumentException('Unsupported GPS path cursor version.');
        }

        return $state;
    }

    private static function sign(string $payload): string
    {
        $key = (string) config('app.key', 'pistat-gps-path-v2-development-key');

        return hash_hmac('sha256', $payload, $key);
    }
}
