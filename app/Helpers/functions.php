<?php

use Morilog\Jalali\Jalalian;

/**
 * Convert a Carbon date to Jalali date
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
