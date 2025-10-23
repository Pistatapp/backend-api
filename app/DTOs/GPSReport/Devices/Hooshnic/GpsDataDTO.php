<?php namespace App\DTOs\GPSReport\Devices\Hooshnic;

use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

readonly class GpsDataDTO
{
    public function __construct(
        public string $deviceVersion,  // +Hooshnic:V1.06
        public array  $latlon,         // [lat, lon]
        public string $reserved1,      // reserved
        public Carbon $dateTime,       // converted to Tehran time
        public int    $speed,          // speed (km/h)
        public string $reserved2,      // reserved
        public int    $status,         // 0=off, 1=on
        public int    $ewDirection,    // east/west direction flag
        public int    $nsDirection,    // north/south direction flag
        public string $imei            // device IMEI
    )
    {
    }

    /**
     * Validation rules.
     */
    public static function rules(): array
    {
        return [
            'deviceVersion' => ['required'],
            'latlon' => ['required', 'array', 'size:2'],
            'latlon.0' => ['numeric', 'between:-90,90'],
            'latlon.1' => ['numeric', 'between:-180,180'],
            'speed' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'integer', 'in:0,1'],
            'ewDirection' => ['required', 'integer', 'in:0,1,3'],
            'nsDirection' => ['required', 'integer', 'in:1,3,4'],
            'imei' => ['required', 'string', 'size:15'],
            'dateTime' => ['required'],
        ];
    }

    /**
     * Parse a single raw GPS line into DTO.
     */
    public static function fromRaw(string $raw): ?self
    {
        try {

            $fields = explode(',', trim($raw));

            // Expect exactly 12 fields
            if (count($fields) < 12) {
                self::logInvalid($raw, 'Invalid field count: ' . count($fields));
                return null;
            }

            [$deviceVersion, $lat, $lon, $reserved1,
                $date, $time, $speed, $reserved2, $status,
                $ew, $ns, $imei] = $fields;


            // Convert UTC to Tehran time
            $dateTime = Carbon::createFromFormat('dmyHis', $date . $time, 'UTC')
                ->setTimezone('Asia/Tehran');

            // Convert NMEA coordinates to decimal degrees
            $latlon = [
                self::nmeaToDecimalDegrees($lat),
                self::nmeaToDecimalDegrees($lon),
            ];

            $data = [
                'deviceVersion' => $deviceVersion,
                'latlon' => $latlon,
                'reserved1' => $reserved1,
                'dateTime' => $dateTime,
                'speed' => (int)$speed,
                'reserved2' => $reserved2,
                'status' => (int)$status,
                'ewDirection' => (int)$ew,
                'nsDirection' => (int)$ns,
                'imei' => $imei,
            ];

            $validator = Validator::make($data, self::rules());
            if ($validator->fails()) {
                self::logInvalid($raw, json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE));
                return null;
            }

            return new self(...$data);

        } catch (\Throwable $e) {
            self::logInvalid($raw, 'Exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Convert NMEA coordinate to decimal degrees.
     */
    private static function nmeaToDecimalDegrees(string $nmea): float
    {
        $n = (float)$nmea;
        $degrees = floor($n / 100);
        $minutes = ($n - ($degrees * 100)) / 60;
        return round($degrees + $minutes, 6);
    }

    /**
     * Log invalid GPS records to file for later inspection.
     */
    private static function logInvalid(string $raw, string $reason): void
    {
        $file = storage_path('logs/invalid_gps_data.json');

        $entry = [
            'timestamp' => now()->toDateTimeString(),
            'raw_data'  => $raw,
            'reason'    => $reason,
        ];

        $existing = [];

        // Read existing file safely (use global functions with leading backslash)
        if (\file_exists($file)) {
            $content = \file_get_contents($file);
            $decoded = @\json_decode($content, true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }

        $existing[] = $entry;

        // Write back (overwrite). Using global json_encode and file_put_contents.
        \file_put_contents($file, \json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Convert DTO to array for DB insertion.
     */
    public function toArray(): array
    {
        return [
            'device_version' => $this->deviceVersion,
            'latlon' => $this->latlon,
            'reserved1' => $this->reserved1,
            'date_time' => $this->dateTime->toDateTimeString(),
            'speed' => $this->speed,
            'reserved2' => $this->reserved2,
            'status' => $this->status,
            'ew_direction' => $this->ewDirection,
            'ns_direction' => $this->nsDirection,
            'imei' => $this->imei,
        ];
    }
}
