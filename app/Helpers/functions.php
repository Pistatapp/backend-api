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
        return Jalalian::fromFormat('Y/m/d', $jalaliDate)->toCarbon();
    } catch (\Exception $e) {
        throw new \InvalidArgumentException('Invalid Jalali date format');
    }
}

/**
 * Check if a date is in Jalali format
 *
 * @param string $date
 * @return bool
 */
function is_jalali_date(string $date): bool
{
    return preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $date);
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

/**
 * Get the weather API service
 *
 * @return \App\Services\WeatherApiService
 */
function weather_api()
{
    return app('weather-api');
}

/**
 * Get the fully qualified class name of a model based on the model type.
 *
 * @param string $model_type The type of the model (e.g., 'user', 'post').
 * @return string The fully qualified class name of the model.
 */
function getModelClass(string $model_type)
{
    $model_type = str_replace(' ', '', ucwords(str_replace('_', ' ', $model_type)));
    $class = "App\\Models\\{$model_type}";

    if (!class_exists($class)) {
        throw new \InvalidArgumentException("Invalid model type: {$model_type}");
    }

    return $class;
}

/**
 * Retrieve an instance of a model by its type and ID.
 *
 * @param string $model_type The type of the model (e.g., 'user', 'post').
 * @param string $model_id The ID of the model instance to retrieve.
 * @return \Illuminate\Database\Eloquent\Model The model instance.
 * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the model instance is not found.
 */
function getModel(string $model_type, string $model_id)
{
    $model_type = getModelClass($model_type);
    return app($model_type)->findOrFail($model_id);
}

/**
 * Get the Zarinpal service
 *
 * @return \App\Services\Zarinpal\Zarinpal
 */
function zarinpal()
{
    return app('zarinpal');
}
