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
    public function parse(string $data)
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
        // Validate data format before processing
        if (!$this->isValidDataFormat($data)) {
            return null;
        }

        $dataFields = explode(',', $data);

        $coordinate = $this->convertNmeaToDecimalDegrees($dataFields[1], $dataFields[2]);
        $dateTime = $this->parseDateTime($dataFields[4], $dataFields[5]);

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
     * Validate if the data follows the expected GPS device format
     * Expected format: +Hooshnic:V1.06,3637.76590,05254.8993,000,251028,164931,000,004,1,3,1,868064073179027
     *
     * @param string $data
     * @return bool
     */
    private function isValidDataFormat(string $data): bool
    {
        $pattern = '/^\+[A-Za-z]+:[A-Za-z0-9.]+,\d{4}\.\d{5},\d{5}\.\d{4},\d{3},\d{6},\d{6},\d{3},\d{3},\d,\d,\d,\d{15}$/';

        return preg_match($pattern, $data) === 1;
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
     * Decode and prepare the data received from the GPS device
     *
     * @param string $jsonData
     * @return array
     */
    private function decodeJsonData(string $jsonData): array
    {
        $trimmedData = rtrim($jsonData, '.');
        $trimmedData = str_replace('}{', '},{', $trimmedData);
        $trimmedData = str_replace(',{}', '', $trimmedData);
        // Remove trailing commas between },] pattern
        $trimmedData = preg_replace('/\},\s*\]/', '}]', $trimmedData);
        $trimmedData = rtrim($trimmedData, ',');
        $decodedData = json_decode($trimmedData, true);

        $decodedData = is_array($decodedData) ? $decodedData : [];

        return $decodedData;
    }
}
