<?php

namespace App\Services\GPSReport;

use App\Services\GPSReport\Devices\Hooshnic\HooshnicParserService;
use Illuminate\Support\Facades\Log;

class GpsParserManager
{
    /**
     * Detect device type and delegate parsing to the correct service.
     *
     * @param string $rawPayload
     * @return array|null
     */
    public function parse(string $rawPayload): ?array
    {
        $deviceType = $this->detectDeviceType($rawPayload);

        switch ($deviceType) {
            case 'hooshnic':
                $parser = app(HooshnicParserService::class);
                break;

            // future devices
            // case 'teltonika':
            //     $parser = app(TeltonikaParserService::class);
            //     break;

            default:
                Log::warning('Unknown GPS device type detected', ['payload' => substr($rawPayload, 0, 100)]);
                return null;
        }

        return $parser->parse($rawPayload);
    }

    /**
     * Detect the GPS device type based on the raw payload pattern.
     */
    private function detectDeviceType(string $payload): ?string
    {
        $input = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Invalid JSON payload in detectDeviceType: ' . json_last_error_msg());
            return null;
        }

        if (!isset($input[0]['data']) || !is_string($input[0]['data'])) {
            Log::warning('Missing or invalid "data" field in payload');
            return null;
        }

        $data = trim($input[0]['data']);

        $detectionRules = [
            'hooshnic' => '/^\+Hooshnic:/',
            'teltonika' => '/Teltonika/',
        ];

        foreach ($detectionRules as $deviceType => $pattern) {
            if (preg_match($pattern, $data)) {
                Log::info("Device detected: {$deviceType} from payload: " . substr($data, 0, 50) . '...');
                return $deviceType;
            }
        }

        Log::warning('Unknown device type in payload: ' . substr($data, 0, 50) . '...');
        return null;
    }
}
