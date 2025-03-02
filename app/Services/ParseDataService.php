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
     */
    public function parse(string $data): array
    {
        $decodedData = $this->decodeJsonData($data);
        $processedData = array_filter(array_map([$this, 'processDataItem'], $decodedData));

        // Order the data by date time
        usort($processedData, function ($a, $b) {
            return $a['date_time'] <=> $b['date_time'];
        });

        return $processedData;
    }

    /**
     * Process a single item of data
     *
     * @param array $dataItem
     * @return array|null
     */
    private function processDataItem(array $dataItem)
    {
        if (!$this->isValidFormat($dataItem['data'])) {
            return null;
        }

        $dataFields = explode(',', $dataItem['data']);
        $coordinates = $this->parseCoordinates($dataFields[1], $dataFields[2]);
        $dateTime = $this->parseDateTime($dataFields[4], $dataFields[5]);

        if (!$dateTime->isToday()) {
            return null;
        }

        return [
            'latitude' => $coordinates['latitude'],
            'longitude' => $coordinates['longitude'],
            'speed' => (int)$dataFields[6],
            'status' => (int)$dataFields[8],
            'date_time' => $dateTime,
            'imei' => $dataFields[9],
            'is_stopped' => (int)$dataFields[6] == 0 || (int)$dataFields[8] == 0,
            'stoppage_time' => 0,
            'is_starting_point' => false,
            'is_ending_point' => false,
        ];
    }

    /**
     * Convert the coordinates from the GPS device to decimal degrees
     *
     * @param string $latitude
     * @param string $longitude
     * @return array
     */
    private function parseCoordinates($latitude, $longitude)
    {
        // Convert latitude and longitude to double
        $latitude = doubleval($latitude);
        $longitude = doubleval($longitude);

        // Convert latitude to decimal degrees
        $latitudeDecimalDegrees = $this->toDecimalDegrees($latitude);

        // Convert longitude to decimal degrees
        $longitudeDecimalDegrees = $this->toDecimalDegrees($longitude);

        return [
            'latitude' => $latitudeDecimalDegrees,
            'longitude' => $longitudeDecimalDegrees
        ];
    }

    /**
     * Convert coordinates to decimal degrees
     *
     * @param float $coordinate
     * @return float
     */
    private function toDecimalDegrees(float $coordinate): float
    {
        $degrees = floor($coordinate / 100);
        $minutes = $coordinate - ($degrees * 100);
        return $degrees + ($minutes / 60);
    }

    /**
     * Convert the date and time from the GPS device to Carbon instance
     *
     * @param string $date
     * @param string $time
     * @return Carbon
     */
    private function parseDateTime(string $date, string $time)
    {
        return Carbon::createFromFormat('ymdHis', $date . $time)->tz('Asia/Tehran');
    }

    /**
     * Check the format of the data received from the GPS device
     *
     * @param string $data
     * @return int
     */
    private function isValidFormat(string $data)
    {
        $pattern = '/^\+Hooshnic:V\d+\.\d+,\d+\.\d+,\d+\.\d+,\d+,\d+,\d+,\d+,\d+,\d+$/';

        return preg_match($pattern, $data);
    }

    /**
     * Correct the data format to be a valid JSON format
     *
     * @param string $data
     * @return string
     */
    private function correctJsonFormat(string $data): string
    {
        // Add commas between JSON objects if they are missing
        $correctedData = preg_replace('/}\s*{/', '},{', $data);
        // Wrap the corrected data in square brackets to form a valid JSON array
        return '[' . $correctedData . ']';
    }

    /**
     * Decode and prepare the data received from the GPS device
     *
     * @param string $jsonData
     * @return array
     */
    private function decodeJsonData(string $jsonData)
    {
        $trimmedData = rtrim($jsonData, ".");
        $correctedData = $this->correctJsonFormat($trimmedData);
        return json_decode($correctedData, true);
    }
}
