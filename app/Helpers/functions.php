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

    if (!is_jalali_date($jalaliDate)) {
        throw new \InvalidArgumentException('Invalid Jalali date format');
    }

    try {
        return Jalalian::fromFormat('Y/m/d', $jalaliDate)->toCarbon();
    } catch (\Exception $e) {
        throw new \InvalidArgumentException('Invalid Jalali date format');
    }
}

/**
 * Check if a date is in valid Jalali format and year range
 *
 * Accepts only dates in the format YYYY/MM/DD where year is 13xx or 14xx (Jalali calendar)
 *
 * @param string $date
 * @return bool
 */
function is_jalali_date(string $date): bool
{
    // Check for correct format: 4 digits/2 digits/2 digits
    if (!preg_match('/^(13|14)\\d{2}\/\\d{2}\/\\d{2}$/', $date)) {
        return false;
    }
    // Optionally, check for valid month and day ranges
    [$year, $month, $day] = explode('/', $date);
    $year = (int)$year;
    $month = (int)$month;
    $day = (int)$day;
    // Jalali months: 1-12, days: 1-31 (1-6: 31 days, 7-11: 30 days, 12: 29 or 30)
    if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
        return false;
    }
    if ($month <= 6 && $day > 31) {
        return false;
    }
    if ($month >= 7 && $month <= 11 && $day > 30) {
        return false;
    }
    if ($month == 12 && $day > 30) {
        return false;
    }
    return true;
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
 * @param int $seconds
 * @return string
 */
function to_time_format(int $seconds): string
{
    $hours = floor($seconds / 3600);
    $remainingSeconds = $seconds % 3600;
    $minutes = floor($remainingSeconds / 60);
    $remainingSeconds = $remainingSeconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
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
        'field' => \App\Models\Field::class,
        'row' => \App\Models\Row::class,
        'tree' => \App\Models\Tree::class,
        'farm' => \App\Models\Farm::class,
        'operation' => \App\Models\Operation::class,
        'labour' => \App\Models\Labour::class,
        'pump' => \App\Models\Pump::class,
        'valve' => \App\Models\Valve::class,
        'crop_type' => \App\Models\CropType::class,
        'pest' => \App\Models\Pest::class,
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
 * @param string $unit Unit of distance ('km', 'm', 'cm', etc.). Default is 'km'
 * @return float The distance between the two points in the specified unit
 */
function calculate_distance(array $point1, array $point2, string $unit = 'km'): float
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

    $distance = $earthRadius * $c; // Distance in kilometers

    // Convert to the requested unit
    $unit = strtolower($unit);
    $factors = [
        'km'  => 1,
        'm'   => 1000,
        'cm'  => 100000,
        'mm'  => 1000000,
        'mi'  => 0.621371,
        'nmi' => 0.539957,
        'ft'  => 3280.84,
    ];
    $factor = $factors[$unit] ?? 1;
    return (float)($distance * $factor);
}

/**
 * Convert NMEA (ddmm.mmmm) to decimal degrees
 *
 * @param string $nmea
 * @return float
 */
function nmea_to_decimal(string $nmea): float
{
    $degrees = floor($nmea / 100);
    $minutes = ($nmea - ($degrees * 100)) / 60;
    return round($degrees + $minutes, 6);
}
