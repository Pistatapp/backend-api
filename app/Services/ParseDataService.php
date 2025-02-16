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
    public function parse(string $data)
    {
        $data = $this->decodeAndPrepareData($data);

        $formatedData = [];

        foreach ($data as $item) {
            if ($this->checkFormat($item['data'])) {
                $item = explode(',', $item['data']);
                $coordinates = $this->convertCoordinates($item[1], $item[2]);
                $dateTime = $this->convertDateTime($item[4], $item[5]);

                if (!$dateTime->isToday()) continue;

                $formatedData[] = [
                    'latitude' => $coordinates['latitude'],
                    'longitude' => $coordinates['longitude'],
                    'speed' => (int)$item[6],
                    'status' => (int)$item[8],
                    'date_time' => $dateTime,
                    'imei' => $item[9],
                    'is_stopped' => (int)$item[6] == 0 || (int)$item[8] == 0,
                    'stoppage_time' => 0,
                    'is_starting_point' => false,
                    'is_ending_point' => false,
                ];
            }
        }

        // Order the data by date time
        usort($formatedData, function ($a, $b) {
            return $a['date_time'] <=> $b['date_time'];
        });

        return $formatedData;
    }

    /**
     * Convert the coordinates from the GPS device to decimal degrees
     *
     * @param string $latitude
     * @param string $longitude
     * @return array
     */
    private function convertCoordinates($latitude, $longitude)
    {
        // Convert latitude and longitude to double
        $latitude = doubleval($latitude);
        $longitude = doubleval($longitude);

        // Calculate latitude degrees and minutes
        $latitudeDegrees = floor($latitude / 100);
        $latitudeMinutes = $latitude - ($latitudeDegrees * 100);

        // Calculate longitude degrees and minutes
        $longitudeDegrees = floor($longitude / 100);
        $longitudeMinutes = $longitude - ($longitudeDegrees * 100);

        // Convert latitude and longitude decimal minutes to decimal degrees
        $latitudeDecimalDegrees = $latitudeDegrees + ($latitudeMinutes / 60);
        $longitudeDecimalDegrees = $longitudeDegrees + ($longitudeMinutes / 60);

        return [
            'latitude' => number_format($latitudeDecimalDegrees, 5),
            'longitude' => number_format($longitudeDecimalDegrees, 5)
        ];
    }

    /**
     * Convert the date and time from the GPS device to Carbon instance
     *
     * @param string $date
     * @param string $time
     * @return Carbon
     */
    private function convertDateTime(string $date, string $time)
    {
        return Carbon::createFromFormat('ymdHis', $date . $time)
            ->addHours(3)
            ->addMinutes(30);
    }

    /**
     * Check the format of the data received from the GPS device
     *
     * @param string $data
     * @return int
     */
    private function checkFormat(string $data)
    {
        $pattern = '/^\+Hooshnic:V\d+\.\d+,\d+\.\d+,\d+\.\d+,\d+,\d+,\d+,\d+,\d+,\d+,\d+$/';

        return preg_match($pattern, $data);
    }

    /**
     * Decode and prepare the data received from the GPS device
     *
     * @param string $content
     * @return array
     */
    private function decodeAndPrepareData(string $content)
    {
        $data = rtrim($content, ".");
        $data = json_decode($data, true);
        return $data;
    }
}
