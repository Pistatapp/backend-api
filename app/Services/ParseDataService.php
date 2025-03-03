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

        usort($processedData, fn($a, $b) => $a['date_time'] <=> $b['date_time']);

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
    private function parseCoordinates(string $latitude, string $longitude): array
    {
        return [
            'latitude' => $this->toDecimalDegrees((float)$latitude),
            'longitude' => $this->toDecimalDegrees((float)$longitude)
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
        $minutes = floor(($coordinate - ($degrees * 100)) * 100) / 100;
        $seconds = (($coordinate - ($degrees * 100)) * 100 - $minutes * 100) * 60;
        return $degrees + ($minutes / 60) + ($seconds / 3600);
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
     * Check the format of the data received from the GPS device
     *
     * @param string $data
     * @return bool
     */
    private function isValidFormat(string $data): bool
    {
        $pattern = '/^\+Hooshnic:V\d+\.\d{2},\d{4}\.\d{5},\d{5}\.\d{4},\d{3},\d{6},\d{6},\d{3},\d{3},\d,\d{15}$/';
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
        return $correctedData;
    }

    /**
     * Decode and prepare the data received from the GPS device
     *
     * @param string $jsonData
     * @return array
     */
    private function decodeJsonData(string $jsonData): array
    {
        $trimmedData = rtrim($jsonData, ".");
        $correctedData = $this->correctJsonFormat($trimmedData);
        return json_decode($correctedData, true);
    }
}
