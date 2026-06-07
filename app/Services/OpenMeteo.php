<?php

namespace App\Services;

use App\Contracts\WeatherProvider;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class OpenMeteo implements WeatherProvider
{
    /**
     * Get the current weather for the location.
     *
     * @param string $location
     * @return array
     */
    public function current(string $location): array
    {
        $coords = $this->parseLocation($location);

        return $this->fetchWeatherData('forecast', [
            'latitude' => $coords['latitude'],
            'longitude' => $coords['longitude'],
            'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,is_day,precipitation,rain,showers,snowfall,weather_code,cloud_cover,pressure_msl,surface_pressure,wind_speed_10m,wind_direction_10m,wind_gusts_10m',
            'timezone' => 'auto',
        ]);
    }

    /**
     * Get the forecast weather for the location.
     *
     * @param string $location
     * @param int $days
     * @return array
     */
    public function forecast(string $location, int $days): array
    {
        $coords = $this->parseLocation($location);

        return $this->fetchWeatherData('forecast', [
            'latitude' => $coords['latitude'],
            'longitude' => $coords['longitude'],
            'forecast_days' => $days,
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,apparent_temperature_max,apparent_temperature_min,sunrise,sunset,daylight_duration,sunshine_duration,uv_index_max,precipitation_sum,rain_sum,showers_sum,snowfall_sum,precipitation_hours,precipitation_probability_max,wind_speed_10m_max,wind_gusts_10m_max,wind_direction_10m_dominant',
            'timezone' => 'auto',
        ]);
    }

    /**
     * Get the forecast weather for the location on a specific future date.
     *
     * @param string $location
     * @param string $date
     * @return array
     */
    public function future(string $location, string $date): array
    {
        $coords = $this->parseLocation($location);
        $targetDate = Carbon::parse($date);
        $daysDifference = Carbon::now()->startOfDay()->diffInDays($targetDate, false);

        // Open-Meteo Forecast endpoint allows specific start/end dates up to 16 days out
        if ($daysDifference >= 0 && $daysDifference <= 16) {
            return $this->fetchWeatherData('forecast', [
                'latitude' => $coords['latitude'],
                'longitude' => $coords['longitude'],
                'start_date' => $targetDate->format('Y-m-d'),
                'end_date' => $targetDate->format('Y-m-d'),
                'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum',
                'timezone' => 'auto',
            ]);
        }

        // For long-range projections beyond 16 days, route to Climate Change API (CMIP6 models)
        return $this->fetchWeatherData('climate', [
            'latitude' => $coords['latitude'],
            'longitude' => $coords['longitude'],
            'start_date' => $targetDate->format('Y-m-d'),
            'end_date' => $targetDate->format('Y-m-d'),
            'models' => 'best_match',
            'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum',
        ]);
    }

    /**
     * Get the historical weather for the location.
     *
     * @param string $location
     * @param string $startDt
     * @param string $endDt
     * @return array
     */
    public function history(string $location, string $startDt, string $endDt): array
    {
        $coords = $this->parseLocation($location);
        $start = Carbon::parse($startDt);
        $end = $endDt ? Carbon::parse($endDt) : Carbon::now();
        $allData = [];

        while ($start->diffInDays($end) > 31) {
            $nextEnd = $start->copy()->addDays(31);
            $data = $this->fetchWeatherData('archive', [
                'latitude' => $coords['latitude'],
                'longitude' => $coords['longitude'],
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $nextEnd->format('Y-m-d'),
                'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum',
                'timezone' => 'auto',
            ]);
            $allData = array_merge_recursive($allData, $data);
            $start = $nextEnd->copy()->addDay();
        }

        $data = $this->fetchWeatherData('archive', [
            'latitude' => $coords['latitude'],
            'longitude' => $coords['longitude'],
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum',
            'timezone' => 'auto',
        ]);

        $allData = array_merge_recursive($allData, $data);

        return $allData;
    }

    /**
     * Fetch weather data from the designated Open-Meteo subdomain API endpoint.
     *
     * @param string $endpoint
     * @param array $queryParams
     * @return array
     */
    private function fetchWeatherData(string $endpoint, array $queryParams): array
    {
        // Select domain matching standard Open-Meteo segmented service routing
        $baseUrl = match ($endpoint) {
            'archive' => 'https://archive-api.open-meteo.com/v1/archive',
            'climate' => 'https://climate-api.open-meteo.com/v1/climate',
            default   => 'https://api.open-meteo.com/v1/forecast',
        };

        // Open-Meteo free tier requires no key. Commercial configurations override domains
        $apiKey = config('services.openmeteo.key');
        if ($apiKey) {
            $queryParams['apikey'] = $apiKey;
            $commercialDomain = config('services.openmeteo.domain', 'customer-api.open-meteo.com');
            $baseUrl = str_replace(
                ['api.open-meteo.com', 'archive-api.open-meteo.com', 'climate-api.open-meteo.com'],
                $commercialDomain,
                $baseUrl
            );
        }

        $response = Http::get($baseUrl, $queryParams);

        abort_if($response->failed(), $response->status(), $response->body());

        return $response->json();
    }

    /**
     * Helper to guarantee coordinate parameters from generic query strings.
     * Maps textual location strings to WGS84 coordinates if raw lat/long values aren't given.
     *
     * @param string $location
     * @return array
     */
    private function parseLocation(string $location): array
    {
        // Check if string contains comma-separated decimal coordinates (e.g., "52.52,13.41")
        if (preg_match('/^(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)$/', $location, $matches)) {
            return [
                'latitude'  => $matches[1],
                'longitude' => $matches[2],
            ];
        }

        // Resolves alphanumeric place names to coordinates using the Geocoding API
        $response = Http::get('https://geocoding-api.open-meteo.com/v1/search', [
            'name'   => $location,
            'count'  => 1,
            'format' => 'json',
        ]);

        if ($response->successful() && isset($response->json()['results'][0])) {
            $result = $response->json()['results'][0];
            return [
                'latitude'  => $result['latitude'],
                'longitude' => $result['longitude'],
            ];
        }

        abort(422, "Unable to resolve coordinates matching location string: {$location}");
    }
}
