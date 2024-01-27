<?php

namespace App\Helpers;

use Carbon\Carbon;

/**
 * Calculate the great-circle distance between two points, with
 * 
 * @param array $point1
 * @param array $point2
 * @return float
 */
function haversineGreatCircleDistance($point1, $point2)
{
    $latitudeFrom = $point1['latitude'];
    $longitudeFrom = $point1['longitude'];
    $latitudeTo = $point2['latitude'];
    $longitudeTo = $point2['longitude'];

    // convert from degrees to radians
    $latFrom = deg2rad($latitudeFrom);
    $lonFrom = deg2rad($longitudeFrom);
    $latTo = deg2rad($latitudeTo);
    $lonTo = deg2rad($longitudeTo);

    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;

    $earthRadius = 6371000; // Earth's radius in meters

    $angle = 2 * asin(
        sqrt(
            pow(sin($latDelta / 2), 2) +
                cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        )
    );

    return $angle * $earthRadius;
}

/**
 * Convert the coordinates.
 *
 * @param  string  $latitude
 * @param  string  $longitude
 * @return array
 */
function convertCoordinates($latitude, $longitude)
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
 * Convert date time to GMT.
 *
 * @param  string  $dateTime
 * @return Carbon
 */
function convertDateTimeToGMT($dateTime)
{
    return Carbon::createFromFormat('ymdHis', $dateTime)->addHours(3)->addMinutes(30);
}
