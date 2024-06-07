<?php

use Morilog\Jalali\Jalalian;

/**
 * Convert a Jalali date to Carbon
 *
 * @param string $jalaliDate
 * @return \Carbon\Carbon
 */
function jalali_to_carbon(string $jalaliDate): \Carbon\Carbon
{
    try {
        $date = Jalalian::fromFormat('Y/m/d', $jalaliDate)->toCarbon();
    } catch (\Exception $e) {
        throw new \Exception('Invalid Jalali date format');
    }

    return $date;
}

/**
 * Convert a time string to hours
 *
 * @param string $time
 * @return float
 */
function time_to_hours(string $time): float
{
    $timeParts = explode(':', $time);
    $hours = (int) $timeParts[0];
    $minutes = (int) $timeParts[1];
    $hours += $minutes / 60;
    return $hours;
}

/**
 * Get the active farm of the authenticated user
 *
 * @return \App\Models\Farm
 */
function get_active_farm()
{
    return auth()->user()->active_farm;
}

/**
 * Calculate the area of a polygon with any number of corners
 *
 * @param array $points
 * @return float
 */
function calculate_polygon_area(array $points): float
{
    $numPoints = count($points);
    if ($numPoints < 3) {
        throw new \Exception('A polygon must have at least 3 points');
    }

    $area = 0;
    for ($i = 0; $i < $numPoints; $i++) {
        $x1 = $points[$i][0];
        $y1 = $points[$i][1];
        $x2 = $points[($i + 1) % $numPoints][0];
        $y2 = $points[($i + 1) % $numPoints][1];
        $area += ($x1 * $y2 - $x2 * $y1);
    }

    return abs($area / 2);
}
