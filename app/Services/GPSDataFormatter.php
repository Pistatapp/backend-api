<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use App\Models\GpsDevice;

class GPSDataFormatter
{
    /**
     * Format the data.
     *
     * @param  array  $data
     * @return array $validatedData
     */
    public function format($data)
    {
        $validatedData = [
            'device' => '',
            'reports' => [],
        ];

        foreach ($data as $item) {
            $validatedData['reports'][] = $this->validate($item['data']);
        }

        // sort the data by date time
        usort($validatedData['reports'], function ($a, $b) {
            return $a['date_time'] <=> $b['date_time'];
        });

        $device = $this->getDevice($validatedData['reports'][0]['imei']);

        throw_unless($device, 'Device not found');

        $validatedData['device'] = $device;

        return $validatedData;
    }

    /**
     * Convert the coordinates.
     *
     * @param  string  $latitude
     * @param  string  $longitude
     * @return array
     */
    private function convertCoordinates($latitude, $longitude)
    {
        // Convert latitude and longitude to double
        $latitude = floatval($latitude);
        $longitude = floatval($longitude);

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
            'longitude' => number_format($longitudeDecimalDegrees, 5),
        ];
    }

    /**
     * Get the date time instance.
     *
     * @param  string  $dateTime
     * @return Carbon
     */
    private function getDateTime($dateTime)
    {
        return Carbon::createFromFormat('ymdHis', $dateTime)->addHours(3)->addMinutes(30);
    }

    /**
     * Validate the data.
     *
     * @param  string  $data
     * @return array
     *
     * @throws \RuntimeException
     */
    protected function validate($data)
    {
        $pattern = '/^\+Hooshnic:V\d+\.\d+,\d+\.\d+,\d+\.\d+,\d+,\d+,\d+,\d+,\d+,\d+,\d+$/';

        throw_unless(preg_match($pattern, $data), 'Invalid data format');

        $data = explode(',', $data);

        $dateTime = $this->getDateTime($data[4] . $data[5]);
        $coordinates = $this->convertCoordinates($data[1], $data[2]);

        throw_unless($dateTime->isToday(), 'Invalid date');

        throw_if($data[6] > 0 && $data[8] == 0, 'Invalid status');

        return [
            'latitude' => $coordinates['latitude'],
            'longitude' => $coordinates['longitude'],
            'speed' => (int) $data[6],
            'status' => $data[8],
            'imei' => $data[9],
            'date_time' => $dateTime,
            'stopped' => $data[6] == 0 ? true : false,
            'stoppage_amount' => 0,
            'is_start_point' => false,
            'is_end_point' => false,
        ];
    }

    /**
     * Get the device instance.
     * 
     * @param string $imei
     * @return GpsDevice
     */
    private function getDevice($imei)
    {
        return Cache::remember('gps_device_' . $imei, 3600, function () use ($imei) {
            return GpsDevice::whereHas('user')
                ->whereHas('trucktor')
                ->where('imei', $imei)
                ->select('id', 'trucktor_id', 'imei')
                ->with('trucktor:id,start_work_time,end_work_time,expected_daily_work_time')
                ->first();
        });
    }
}
