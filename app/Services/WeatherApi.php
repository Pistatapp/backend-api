<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class WeatherApi
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
     * Get the historical weather for the location.
     *
     * @param string $location
     * @param string $startDt
     * @param string|null $endDt
     * @return array
     */
    public function history(string $location, string $startDt, string $endDt = null): array
    {
        $start = Carbon::parse($startDt);
        $end = $endDt ? Carbon::parse($endDt) : Carbon::now();
        $allData = [];

        while ($start->diffInDays($end) > 31) {
            $nextEnd = $start->copy()->addDays(31);
            $data = $this->fetchWeatherData('history.json', [
                'q' => $location,
                'dt' => $start->format('Y-m-d'),
                'end_dt' => $nextEnd->format('Y-m-d'),
            ]);
            $allData = array_merge_recursive($allData, $data);
            $start = $nextEnd->copy()->addDay();
        }

        $data = $this->fetchWeatherData('history.json', [
            'q' => $location,
            'dt' => $start->format('Y-m-d'),
            'end_dt' => $end->format('Y-m-d'),
        ]);

        $allData = array_merge_recursive($allData, $data);

        return $allData;
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

        $response = Http::get("https://api.weatherapi.com/v1/{$endpoint}", $queryParams);

        abort_if($response->failed(), $response->status(), $response->body());

        return $response->json();
    }
}
