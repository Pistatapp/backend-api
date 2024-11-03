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
    public function forecast(array $location, int $days): array
    {
        return $this->fetchWeatherData('forecast.json', [
            'q' => implode(',', $location),
            'days' => $days,
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
        $startDt = $this->convertToGregorian($startDt);
        $endDt = $endDt ? $this->convertToGregorian($endDt) : null;

        return $this->fetchWeatherData('history.json', [
            'q' => implode(',', $location),
            'dt' => $startDt,
            'end_dt' => $endDt,
        ]);
    }

    /**
     * Convert Jalali date to Gregorian if necessary.
     *
     * @param string $date
     * @return string
     */
    private function convertToGregorian(string $date): string
    {
        return is_jalali_date($date) ? jalali_to_carbon($date)->format('Y-m-d') : $date;
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

            abort_if($response->clientError(), 400, 'Bad Request');
            abort_if($response->serverError(), 500, 'Internal Server Error');
        } catch (\Exception $e) {
            abort(500, __('Failed to fetch weather data.'));
        }
    }
}
