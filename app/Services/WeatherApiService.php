<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WeatherApiService
{
    /**
     * Get the current weather for the location.
     *
     * @param array $location
     * @return array
     */
    public function current(array $location): array
    {
        return $this->fetchWeatherData('current.json', [
            'q' => implode(',', $location),
        ]);
    }

    /**
     * Get the forecast weather for the location.
     *
     * @param array $location
     * @param int $days
     * @return array
     */
    public function history(array $location, string $startDt, string $endDt = null): array
    {
        // Check if the dates are in Jalali format and convert them to Gregorian
        if (is_jalali_date($startDt)) {
            $startDt = jalali_to_carbon($startDt)->format('Y-m-d');
        }

        if ($endDt && is_jalali_date($endDt)) {
            $endDt = jalali_to_carbon($endDt)->format('Y-m-d');
        }

        return $this->fetchWeatherData('history.json', [
            'q' => implode(',', $location),
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

            return [
                'error' => true,
                'message' => $response->json()['error']['message'] ?? 'Unknown error',
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            return [
                'error' => true,
                'message' => $e->getMessage(),
                'status' => 500,
            ];
        }
    }
}
