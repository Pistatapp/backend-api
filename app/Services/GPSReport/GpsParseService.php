<?php namespace App\Services\GPSReport;

use App\DTOs\GpsDataDTO;
use App\Models\GpsDevice;

class GpsParseService
{
    /**
     * Parse and prepare a GPS report for database insertion.
     *
     * @param string $rawData The raw incoming payload from the GPS device
     * @return array|null        Final structured data for gps_reports, or null if invalid
     */
    public function parse(string $rawData): ?array
    {
        // Step 1: Extract "data" value if wrapped in JSON like {"data":"..."}
        $decoded = json_decode($rawData, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['data'])) {
            $rawData = $decoded['data'];
        }

        // Step 2: Create DTO from the raw GPS string
        $dto = GpsDataDTO::fromRaw($rawData);
        if ($dto === null) {
            // Invalid data automatically logged inside DTO
            return null;
        }

        $dtoData = $dto->toArray();

        // Step 3: Find related GPS device by IMEI
        $gpsDevice = GpsDevice::where('imei', $dtoData['imei'])->first();

        if (!$gpsDevice) {
            // No matching device found → log and ignore
            \Log::warning('Unknown GPS device IMEI: ' . $dtoData['imei']);
            return null;
        }

        // Step 4: Build the final record ready for gps_reports table
        return [
            'gps_device_id' => $gpsDevice->id,
            'raw_data' => $rawData,
            'imei' => $dtoData['imei'],
            'coordinate' => $dtoData['latlon'],
            'speed' => $dtoData['speed'],
            'status' => $dtoData['status'],
            'directions' => [
                'ew' => $dtoData['ew_direction'],
                'ns' => $dtoData['ns_direction']
            ],
            'date_time' => $dtoData['date_time'],
        ];
    }
}
