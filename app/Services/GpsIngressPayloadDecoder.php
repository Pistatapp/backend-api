<?php

namespace App\Services;

use Carbon\Carbon;
use Throwable;

/**
 * Decodes both the current normalized GPS request and the legacy Hooshnics
 * envelope.  The decoder returns one result per wire item so a bad item can be
 * quarantined without rejecting the rest of a batch.
 */
class GpsIngressPayloadDecoder
{
    /**
     * @return array<int, array{index:int, raw_payload:string, data:array<string,mixed>|null, error_reason:string|null}>
     */
    public function decode(string $rawPayload, mixed $decodedPayload = null): array
    {
        $rawPayload = str_replace("\0", '', $rawPayload);
        $decodedPayload ??= $this->decodeJson($rawPayload);

        if (is_array($decodedPayload) && array_key_exists('data', $decodedPayload)) {
            return $this->decodeCollection($decodedPayload['data'], $rawPayload);
        }

        if (is_array($decodedPayload) && array_is_list($decodedPayload)) {
            return $this->decodeCollection($decodedPayload, $rawPayload);
        }

        // A glued-object payload or a payload surrounded by modem noise is not
        // valid JSON as a whole. Recover each balanced object independently.
        $results = [];
        foreach ($this->extractJsonObjects($rawPayload) as $index => $fragment) {
            $item = $this->decodeJson($fragment);
            if (! is_array($item)) {
                $results[] = $this->invalid($index, $fragment, 'Invalid JSON object');
                continue;
            }

            $results = array_merge($results, $this->decodeCollection([$item], $fragment, $index));
        }

        return $results;
    }

    /**
     * @param mixed $collection
     * @return array<int, array{index:int, raw_payload:string, data:array<string,mixed>|null, error_reason:string|null}>
     */
    private function decodeCollection(mixed $collection, string $batchRaw, ?int $indexOffset = null): array
    {
        if (! is_array($collection)) {
            return [$this->invalid(0, $batchRaw, 'data must be an array')];
        }

        $results = [];
        foreach (array_values($collection) as $index => $item) {
            if (is_array($item) && $item === []) {
                // Empty modem padding objects are not GPS Events.
                continue;
            }

            $wireIndex = $indexOffset ?? $index;
            $itemRaw = $this->encodeRaw($item);

            if (! is_array($item)) {
                $results[] = $this->invalid($wireIndex, $itemRaw, 'GPS item must be an object');
                continue;
            }

            if (array_key_exists('data', $item) && is_string($item['data'])) {
                $results[] = $this->decodeLegacyItem($wireIndex, $itemRaw, $item['data']);
                continue;
            }

            if (array_key_exists('data', $item) && is_array($item['data'])) {
                foreach ($this->decodeCollection($item['data'], $itemRaw) as $nested) {
                    $nested['index'] = $wireIndex;
                    $results[] = $nested;
                }
                continue;
            }

            $results[] = [
                'index' => $wireIndex,
                'raw_payload' => $itemRaw,
                'data' => $item,
                'error_reason' => null,
            ];
        }

        return $results;
    }

    /**
     * Decode the legacy +Hooshnic wire frame carried in {"data":"..."}.
     *
     * @return array{index:int, raw_payload:string, data:array<string,mixed>|null, error_reason:string|null}
     */
    private function decodeLegacyItem(int $index, string $rawItem, string $value): array
    {
        $value = rtrim(trim(str_replace(["\0", '\\:'], ['', ':'], $value)), '.');
        $fields = array_map('trim', explode(',', $value));

        if (count($fields) < 12) {
            return $this->invalid($index, $rawItem, 'Legacy Hooshnic item has fewer than 12 fields');
        }

        $header = $fields[0] ?? '';
        $imei = $fields[11] ?? '';
        if (! preg_match('/^\+[A-Za-z][A-Za-z0-9_]*:[A-Za-z0-9._-]+$/', $header)) {
            return $this->invalid($index, $rawItem, 'Invalid legacy Hooshnic header');
        }

        if (! is_numeric($fields[1]) || ! is_numeric($fields[2])) {
            return $this->invalid($index, $rawItem, 'Invalid NMEA coordinate');
        }

        if (! preg_match('/^\d{6}$/', $fields[4]) || ! preg_match('/^\d{6}$/', $fields[5])) {
            return $this->invalid($index, $rawItem, 'Invalid legacy device date/time');
        }

        if (! preg_match('/^\d{15,20}$/', $imei)) {
            return $this->invalid($index, $rawItem, 'Invalid legacy IMEI');
        }

        try {
            $deviceRecordedAt = Carbon::createFromFormat('ymdHis', $fields[4].$fields[5], 'UTC');
            if ($deviceRecordedAt === false) {
                throw new \InvalidArgumentException('invalid date');
            }
        } catch (Throwable) {
            return $this->invalid($index, $rawItem, 'Invalid legacy device date/time');
        }

        // Existing Hooshnics traffic is interpreted as UTC on the wire and is
        // stored as the app's civil time. Keep the raw device timestamp too.
        $storedDateTime = $deviceRecordedAt
            ->copy()
            ->timezone(config('app.timezone', 'Asia/Tehran'))
            ->format('Y-m-d H:i:s');

        return [
            'index' => $index,
            'raw_payload' => $rawItem,
            'data' => [
                'coordinate' => [
                    $this->nmeaToDecimal((float) $fields[1]),
                    $this->nmeaToDecimal((float) $fields[2]),
                ],
                'speed' => (int) ($fields[6] ?? 0),
                'status' => (int) ($fields[8] ?? 0),
                'directions' => [
                    'ew' => (int) ($fields[9] ?? 0),
                    'ns' => (int) ($fields[10] ?? 0),
                ],
                'date_time' => $storedDateTime,
                'device_recorded_at' => $deviceRecordedAt->format('Y-m-d H:i:s'),
                'device_recorded_at_raw' => $fields[4].$fields[5],
                'imei' => $imei,
            ],
            'error_reason' => null,
        ];
    }

    private function nmeaToDecimal(float $coordinate): float
    {
        $degrees = floor($coordinate / 100);
        $minutes = ($coordinate - ($degrees * 100)) / 60;

        return round($degrees + $minutes, 6);
    }

    private function decodeJson(string $payload): mixed
    {
        $payload = trim(str_replace('\\:', ':', $payload));
        if ($payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * Extract balanced JSON objects while ignoring strings and escaped quotes.
     * This handles missing commas, leading/trailing modem noise and CRLF.
     *
     * @return array<int, string>
     */
    private function extractJsonObjects(string $payload): array
    {
        $objects = [];
        $length = strlen($payload);
        $start = null;
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $payload[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            // Quotes in modem noise (for example AT+CIPSTART="TCP",...) are
            // outside a JSON object and must not desynchronise the scanner.
            if ($depth > 0 && $char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
                continue;
            }

            if ($char === '}' && $depth > 0) {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    $objects[] = substr($payload, $start, $i - $start + 1);
                    $start = null;
                }
            }
        }

        if ($start !== null && $start < $length) {
            // Preserve an unterminated object as an explicit invalid item. It
            // must not disappear merely because its closing brace is missing.
            $objects[] = substr($payload, $start);
        }

        return $objects;
    }

    private function encodeRaw(mixed $item): string
    {
        if (is_string($item)) {
            return $item;
        }

        return (string) json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @return array{index:int, raw_payload:string, data:null, error_reason:string}
     */
    private function invalid(int $index, string $rawPayload, string $reason): array
    {
        return [
            'index' => $index,
            'raw_payload' => $rawPayload,
            'data' => null,
            'error_reason' => $reason,
        ];
    }
}
