<?php

use Morilog\Jalali\Jalalian;

/**
 * Convert a Jalali date to Carbon
 *
 * @param string|null $jalaliDate
 * @return \Carbon\Carbon|null
 */
function jalali_to_carbon(?string $jalaliDate): ?\Carbon\Carbon
{
    if (is_null($jalaliDate)) {
        return null; // Handle null input gracefully
    }

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
 * Calculate the center point (centroid) of a polygon
 *
 * @param array $points Array of [x,y] coordinates
 * @return array [x,y] coordinates of the center point
 * @throws \InvalidArgumentException if polygon has less than 3 points
 */
function calculate_polygon_center(array $points): array
{
    $numPoints = count($points);

    if ($numPoints < 3) {
        throw new \InvalidArgumentException('A polygon must have at least 3 points');
    }

    $sumX = 0;
    $sumY = 0;

    foreach ($points as $point) {
        $sumX += $point[0];
        $sumY += $point[1];
    }

    return [
        $sumX / $numPoints,
        $sumY / $numPoints
    ];
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
 * @param string $modelType The type of the model (e.g., 'tractor').
 * @return string The fully qualified class name of the model.
 */
function getModelClass(string $modelType): string
{
    $modelMap = [
        'tractor' => \App\Models\Tractor::class,
    ];

    $modelClass = $modelMap[$modelType] ?? $modelType;

    if (!str_starts_with($modelClass, 'App\\Models\\')) {
        $modelClass = 'App\\Models\\' . ucfirst($modelClass);
    }

    if (!class_exists($modelClass)) {
        throw new InvalidArgumentException("Model class {$modelClass} does not exist");
    }

    return $modelClass;
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

/**
 * Determine if a point is within a polygon
 *
 * @param array $point [x, y] coordinates of the point
 * @param array $polygon Array of [x, y] coordinates representing the polygon
 * @return bool
 */
function is_point_in_polygon(array $point, array $polygon): bool
{
    $x = $point[0];
    $y = $point[1];
    $inside = false;
    $numPoints = count($polygon);

    for ($i = 0, $j = $numPoints - 1; $i < $numPoints; $j = $i++) {
        $xi = $polygon[$i][0];
        $yi = $polygon[$i][1];
        $xj = $polygon[$j][0];
        $yj = $polygon[$j][1];

        $intersect = (($yi > $y) != ($yj > $y)) &&
            ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);
        if ($intersect) {
            $inside = !$inside;
        }
    }

    return $inside;
}

/**
 * Calculate the distance between two points using the Haversine formula
 *
 * @param array $point1 [latitude, longitude] coordinates of the first point
 * @param array $point2 [latitude, longitude] coordinates of the second point
 * @return float The distance between the two points in kilometers
 */
function calculate_distance(array $point1, array $point2): float
{
    $earthRadius = 6371; // Earth's radius in kilometers

    $lat1 = deg2rad($point1[0]);
    $lon1 = deg2rad($point1[1]);
    $lat2 = deg2rad($point2[0]);
    $lon2 = deg2rad($point2[1]);

    $dLat = $lat2 - $lat1;
    $dLon = $lon2 - $lon1;

    $a = sin($dLat / 2) ** 2 +
         cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}
