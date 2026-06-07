<?php

namespace App\Services;

use App\Contracts\WeatherProvider;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class OpenMeteo implements WeatherProvider
{
    private const DAILY_FORECAST_PARAMS = 'weather_code,temperature_2m_max,temperature_2m_min,apparent_temperature_max,apparent_temperature_min,sunrise,sunset,daylight_duration,sunshine_duration,uv_index_max,precipitation_sum,rain_sum,showers_sum,snowfall_sum,precipitation_hours,precipitation_probability_max,wind_speed_10m_max,wind_gusts_10m_max,wind_direction_10m_dominant';

    private const DAILY_ARCHIVE_PARAMS = 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,wind_speed_10m_max,wind_gusts_10m_max';

    private const HOURLY_PARAMS = 'temperature_2m,relative_humidity_2m,dewpoint_2m,cloud_cover,wind_speed_10m';

    /** @see https://open-meteo.com/en/docs */
    private const FORECAST_API_URL = 'https://api.open-meteo.com/v1/forecast';

    /** @see https://open-meteo.com/en/docs/historical-weather-api */
    private const ARCHIVE_API_URL = 'https://archive-api.open-meteo.com/v1/archive';

    /** @see https://open-meteo.com/en/docs/climate-api */
    private const CLIMATE_API_URL = 'https://climate-api.open-meteo.com/v1/climate';

    /** @see https://open-meteo.com/en/docs/geocoding-api */
    private const GEOCODING_API_URL = 'https://geocoding-api.open-meteo.com/v1/search';

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
            'current' => 'temperature_2m,relative_humidity_2m,dewpoint_2m,apparent_temperature,is_day,precipitation,rain,showers,snowfall,weather_code,cloud_cover,pressure_msl,surface_pressure,wind_speed_10m,wind_direction_10m,wind_gusts_10m',
            'timezone' => 'auto',
            'wind_speed_unit' => 'kmh',
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
            'daily' => self::DAILY_FORECAST_PARAMS,
            'hourly' => self::HOURLY_PARAMS,
            'timezone' => 'auto',
            'wind_speed_unit' => 'kmh',
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
                'daily' => self::DAILY_ARCHIVE_PARAMS,
                'hourly' => self::HOURLY_PARAMS,
                'timezone' => 'auto',
                'wind_speed_unit' => 'kmh',
            ]);
            $allData = $this->mergeArchiveData($allData, $data);
            $start = $nextEnd->copy()->addDay();
        }

        $data = $this->fetchWeatherData('archive', [
            'latitude' => $coords['latitude'],
            'longitude' => $coords['longitude'],
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'daily' => self::DAILY_ARCHIVE_PARAMS,
            'hourly' => self::HOURLY_PARAMS,
            'timezone' => 'auto',
            'wind_speed_unit' => 'kmh',
        ]);

        $allData = $this->mergeArchiveData($allData, $data);

        return $allData;
    }

    /**
     * Merge archive API chunks without corrupting nested daily/hourly arrays.
     */
    private function mergeArchiveData(array $existing, array $incoming): array
    {
        if ($existing === []) {
            return $incoming;
        }

        foreach (['daily', 'hourly'] as $section) {
            if (! isset($incoming[$section])) {
                continue;
            }

            foreach ($incoming[$section] as $key => $values) {
                $existing[$section][$key] = array_merge($existing[$section][$key] ?? [], $values);
            }
        }

        return $existing;
    }

    /**
     * Fetch weather data from the designated Open-Meteo API endpoint.
     *
     * @param string $endpoint
     * @param array $queryParams
     * @return array
     */
    private function fetchWeatherData(string $endpoint, array $queryParams): array
    {
        if ($apiKey = $this->commercialApiKey()) {
            $queryParams['apikey'] = $apiKey;
        }

        $response = Http::get($this->resolveApiUrl($endpoint), $queryParams);

        abort_if($response->failed(), $response->status(), $response->body());

        return $response->json();
    }

    /**
     * Resolve the API URL for the requested Open-Meteo service.
     *
     * @see https://open-meteo.com/en/features Commercial plans prefix each service host with "customer-".
     */
    private function resolveApiUrl(string $endpoint): string
    {
        $baseUrl = match ($endpoint) {
            'archive' => self::ARCHIVE_API_URL,
            'climate' => self::CLIMATE_API_URL,
            default => self::FORECAST_API_URL,
        };

        if ($this->commercialApiKey()) {
            $baseUrl = preg_replace(
                '#https://([a-z0-9-]+)\\.open-meteo\\.com#',
                'https://customer-$1',
                $baseUrl
            );
        }

        return $baseUrl;
    }

    private function commercialApiKey(): ?string
    {
        return config('weather-forecast.api.openmeteo.key');
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
        $response = Http::get(self::GEOCODING_API_URL, [
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
