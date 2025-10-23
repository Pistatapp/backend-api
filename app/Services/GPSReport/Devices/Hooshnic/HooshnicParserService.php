<?php namespace App\Services\GPSReport\Devices\Hooshnic;

use App\DTOs\GPSReport\Devices\Hooshnic\GpsDataDTO;
use App\Models\GpsDevice;
use Illuminate\Support\Facades\Log;

class HooshnicParserService
{
    /**
     * Parse and prepare one or more GPS reports for database insertion.
     *
     * @param string $rawPayload Raw incoming payload from the GPS device
     * @return array|null Array of structured records for gps_reports, or null if all invalid
     */
    public function parse(string $rawPayload): ?array
    {
        if (empty($rawPayload)) {
            Log::warning('Empty GPS payload received');
            return null;
        }

        // --- Step 1: Extract and normalize JSON ---
        $normalized = $this->normalizePayload($rawPayload);
        if (empty($normalized)) {
            Log::warning('Unrecognized GPS payload format');
            return null;
        }

        $finalRecords = [];

        // --- Step 2: Iterate over each data object ---
        foreach ($normalized as $item) {
            if (!isset($item['data'])) {
                continue;
            }

            $rawData = trim($item['data']);
            $dto = GpsDataDTO::fromRaw($rawData);

            if ($dto === null) {
                // Invalid data already logged inside DTO
                continue;
            }

            $dtoData = $dto->toArray();

            // --- Step 3: Find GPS device by IMEI ---
            $gpsDevice = GpsDevice::where('imei', $dtoData['imei'])->first();

            if (!$gpsDevice) {
                Log::warning("Unknown GPS IMEI: {$dtoData['imei']}");
                continue;
            }

            // --- Step 4: Build final structured record ---
            $finalRecords[] = [
                'gps_device_id' => $gpsDevice->id,
                'raw_data' => $rawData,
                'imei' => $dtoData['imei'],
                'coordinate' => $dtoData['latlon'],
                'speed' => $dtoData['speed'],
                'status' => $dtoData['status'],
                'directions' => [
                    'ew' => $dtoData['ew_direction'],
                    'ns' => $dtoData['ns_direction'],
                ],
                'date_time' => $dtoData['date_time'],
            ];
        }

        return count($finalRecords) > 0 ? $finalRecords : null;
    }

    /**
     * Normalize incoming payload to a clean array of {"data": "..."} objects.
     * Supports single, multiple, and concatenated logs.
     */
    private function normalizePayload(string $payload): array
    {
        $payload = trim($payload);

        // Case 1: Standard JSON array [{"data":"..."}, {"data":"..."}]
        $json = json_decode($payload, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }

        // Case 2: Multiple JSON objects concatenated without commas
        // → replace '}{' with '},{' and wrap in brackets
        if (str_contains($payload, '}{')) {
            $fixed = '[' . preg_replace('/}\s*{/', '},{', $payload) . ']';
            $json = json_decode($fixed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                return $json;
            }
        }

        // Case 3: Single record {"data":"..."}
        if (preg_match('/^\s*\{.*"data"\s*:\s*".*"\s*}\s*$/', $payload)) {
            $json = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($json['data'])) {
                return [$json];
            }
        }

        // Case 4: Plain string (raw data only)
        if (str_starts_with($payload, '+Hooshnic:')) {
            return [['data' => $payload]];
        }

        return [];
    }
}
