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
        $coordinate = $this->convertNmeaToDecimalDegrees($dataFields[1], $dataFields[2]);
        $dateTime = $this->parseDateTime($dataFields[4], $dataFields[5]);

        if (!$dateTime->isToday()) {
            return null;
        }

        return [
            'coordinate' => $coordinate,
            'speed' => (int)$dataFields[6],
            'status' => (int)$dataFields[8],
            'direction' => (int)$dataFields[9],
            'date_time' => $dateTime,
            'imei' => $dataFields[10],
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
        return array_map('floatval', [
            sprintf('%.6f', $this->nmeaToDecimalDegrees((float)$nmeaLatitude)),
            sprintf('%.6f', $this->nmeaToDecimalDegrees((float)$nmeaLongitude))
        ]);
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
     * Check the format of the data received from the GPS device
     *
     * @param string $data
     * @return bool
     */
    private function isValidFormat(string $data): bool
    {
        $pattern = '/^\+Hooshnic:V\d+\.\d{2},\d{4}\.\d{5},\d{5}\.\d{4},\d{3},\d{6},\d{6},\d{3},\d{3},\d,\d{3},\d{15}$/';
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
        $decodedData = json_decode($correctedData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \JsonException('JSON decode error: ' . json_last_error_msg());
        }

        return $decodedData;
    }
}
