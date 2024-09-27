<?php

use Illuminate\Support\Facades\Auth;
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
        return Jalalian::fromFormat('Y/m/d', $jalaliDate)->toCarbon();
    } catch (\Exception $e) {
        throw new \InvalidArgumentException('Invalid Jalali date format');
    }
}

/**
 * Convert a time string to hours
 *
 * @param string $time
 * @return float
 */
function time_to_hours(string $time): float
{
    [$hours, $minutes] = array_map('intval', explode(':', $time));
    return $hours + $minutes / 60;
}

/**
 * Get the active farm of the authenticated user
 *
 * @return \App\Models\Farm
 */
function get_active_farm()
{
    return Auth::user()->active_farm;
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
        throw new \InvalidArgumentException('A polygon must have at least 3 points');
    }

    $area = 0.0;
    for ($i = 0, $j = $numPoints - 1; $i < $numPoints; $j = $i++) {
        $area += ($points[$j][0] * $points[$i][1]) - ($points[$i][0] * $points[$j][1]);
    }

    return abs($area) / 2.0;
}

/**
 * Convert hours to a time format
 *
 * @param float $hours
 * @return string
 */
function to_time_format(int $minutes): string
{
    $hours = floor($minutes / 60);
    $remainingMinutes = $minutes % 60;
    return sprintf('%02d:%02d', $hours, $remainingMinutes);
}
