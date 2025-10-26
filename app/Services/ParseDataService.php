<?php

namespace App\Services;

use Carbon\Carbon;

class ParseDataService
{
    /**
     * Parse the data received from the GPS device
     *
     * @param string $data
     * @return array
     * @throws \JsonException
     * @throws \InvalidArgumentException
     */
    public function parse(string $data): array
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Input data cannot be empty');
        }

        $decodedData = $this->decodeJsonData($data);

        if (empty($decodedData)) {
            return [];
        }

        $processedData = $this->processDataItems($decodedData);

        // Sort by date_time
        usort($processedData, fn($a, $b) => $a['date_time'] <=> $b['date_time']);

        return $processedData;
    }

    /**
     * Process multiple data items efficiently
     *
     * @param array $decodedData
     * @return array
     */
    private function processDataItems(array $decodedData): array
    {
        $processedData = [];
        $today = Carbon::today();

        foreach ($decodedData as $dataItem) {
            if (!isset($dataItem['data']) || !is_string($dataItem['data'])) {
                continue;
            }

            $processedItem = $this->processDataItem($dataItem['data'], $today);
            if ($processedItem !== null) {
                $processedData[] = $processedItem;
            }
        }

        return $processedData;
    }

    /**
     * Process a single item of data
     *
     * @param string $data
     * @param Carbon $today
     * @return array|null
     */
    private function processDataItem(string $data, Carbon $today): ?array
    {
        // Clean the data by removing parentheses and extracting the core GPS data
        $cleanedData = $this->cleanGpsData($data);

        if (!$this->isValidFormat($cleanedData)) {
            return null;
        }

        $dataFields = explode(',', $cleanedData);

        // Validate required fields exist - the format has 12 fields
        if (count($dataFields) < 12) {
            return null;
        }

        $coordinate = $this->convertNmeaToDecimalDegrees($dataFields[1], $dataFields[2]);
        $dateTime = $this->parseDateTime($dataFields[4], $dataFields[5]);

        if (!$dateTime->isSameDay($today)) {
            return null;
        }

        $speed = (int)$dataFields[6];
        $status = (int)$dataFields[8];
        $ewDirection = (int)$dataFields[9];
        $nsDirection = (int)$dataFields[10];
        $imei = $dataFields[11];

        return [
            'coordinate' => $coordinate,
            'speed' => $speed,
            'status' => $status,
            'directions' => [
                'ew' => $ewDirection,
                'ns' => $nsDirection,
            ],
            'date_time' => $dateTime,
            'imei' => $imei,
        ];
    }

    /**
     * Convert the NMEA coordinates from the GPS device to decimal degrees
     *
     * @param string $nmeaLatitude
     * @param string $nmeaLongitude
     * @return array
     */
    private function convertNmeaToDecimalDegrees(string $nmeaLatitude, string $nmeaLongitude): array
    {
        $latitude = $this->nmeaToDecimalDegrees((float)$nmeaLatitude);
        $longitude = $this->nmeaToDecimalDegrees((float)$nmeaLongitude);

        return [
            round($latitude, 6),
            round($longitude, 6)
        ];
    }

    /**
     * Convert NMEA coordinate to decimal degrees
     *
     * @param float $nmeaCoordinate
     * @return float
     */
    private function nmeaToDecimalDegrees(float $nmeaCoordinate): float
    {
        $degrees = floor($nmeaCoordinate / 100);
        $minutes = ($nmeaCoordinate - ($degrees * 100)) / 60;
        return $degrees + $minutes;
    }

    /**
     * Convert the date and time from the GPS device to Carbon instance
     *
     * @param string $date
     * @param string $time
     * @return Carbon
     */
    private function parseDateTime(string $date, string $time): Carbon
    {
        return Carbon::createFromFormat('ymdHis', $date . $time)->addHours(3)->addMinutes(30);
    }

    /**
     * Clean GPS data by removing parentheses and extracting core data
     *
     * @param string $data
     * @return string
     */
    private function cleanGpsData(string $data): string
    {
        // Remove parentheses from the data
        $cleaned = trim($data, '()');

        // Extract the GPS data part after the colon
        if (strpos($cleaned, ':') !== false) {
            $parts = explode(':', $cleaned, 2);
            if (count($parts) === 2) {
                return $parts[1];
            }
        }

        return $cleaned;
    }

    /**
     * Check the format of the data received from the GPS device
     *
     * @param string $data
     * @return bool
     */
    private function isValidFormat(string $data): bool
    {
        // Updated pattern to match the actual GPS data format:
        // V1.06,3637.75850,05254.9086,000,251016,182121,000,000,1,3,1,86806407317902
        $pattern = '/^V\d+\.\d{2},\d{4,5}\.\d{5},\d{5}\.\d{4},\d{3},\d{6},\d{6},\d{3},\d{3},\d,\d{1,3},\d{1,2},\d{14}$/';
        return preg_match($pattern, $data) === 1;
    }

    /**
     * Correct the data format to be a valid JSON format
     *
     * @param string $data
     * @return string
     */
    private function correctJsonFormat(string $data): string
    {
        $correctedData = preg_replace('/}\s*{/', '},{', $data);

        // Handle the specific format with parentheses around GPS data
        // Convert "(+Hooshnic:V1.06,...)" to "+Hooshnic:V1.06,..."
        $correctedData = preg_replace('/\("([^"]+)"\)/', '"$1"', $correctedData);

        // Remove any remaining '(' or ')' characters from the data
        $correctedData = str_replace(['(', ')'], '', $correctedData);

        return $correctedData;
    }

    /**
     * Decode and prepare the data received from the GPS device
     *
     * @param string $jsonData
     * @return array
     * @throws \JsonException
     */
    private function decodeJsonData(string $jsonData): array
    {
        $trimmedData = rtrim($jsonData, ".");
        $correctedData = $this->correctJsonFormat($trimmedData);

        $decodedData = json_decode($correctedData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \JsonException('JSON decode error: ' . json_last_error_msg());
        }

        if (!is_array($decodedData)) {
            throw new \JsonException('Decoded data is not an array');
        }

        return $decodedData;
    }
}
