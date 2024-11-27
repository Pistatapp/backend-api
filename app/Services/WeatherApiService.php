<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WeatherApiService
{
    /**
     * Get the current weather for the location.
     *
     * @param string $location
     * @return array
     */
    public function current(string $location): array
    {
        return $this->fetchWeatherData('current.json', [
            'q' => $location,
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
        return $this->fetchWeatherData('forecast.json', [
            'q' => $location,
            'days' => $days,
        ]);
    }

    /**
     * Get the forecast weather for the location.
     *
     * @param string $location
     * @param int $days
     * @return array
     */
    public function future(string $location, string $date): array
    {
        return $this->fetchWeatherData('future.json', [
            'q' => $location,
            'dt' => $date,
        ]);
    }

    /**
     * Get the forecast weather for the location.
     *
     * @param string $location
     * @param int $days
     * @return array
     */
    public function history(string $location, string $startDt, string $endDt = null): array
    {
        return $this->fetchWeatherData('history.json', [
            'q' => $location,
            'dt' => $startDt,
            'end_dt' => $endDt,
        ]);
    }

    /**
     * Fetch weather data from the Weather API.
     *
     * @param string $endpoint
     * @param array $queryParams
     * @return array
     */
    private function fetchWeatherData(string $endpoint, array $queryParams): array
    {
        $queryParams['key'] = config('services.weatherapi.key');

        try {
            $response = Http::get("https://api.weatherapi.com/v1/{$endpoint}", $queryParams);

            if ($response->successful()) {
                return $response->json();
            }

            $errorData = $response->json();
            abort($response->status(), json_encode($errorData));
        } catch (\Exception $e) {
            abort(500, $e->getMessage());
        }
    }
}
