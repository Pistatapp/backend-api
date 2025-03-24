<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\WeatherForecastRequest;
use App\Models\Farm;
use Illuminate\Http\Request;

class WeatherForecastController extends Controller
{
    /**
     * Get the weather forecast for the farm.
     *
     * @param WeatherForecastRequest $request
     * @param Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(WeatherForecastRequest $request, Farm $farm)
    {
        $location = $farm->center;

        $type = $request->input('type', 'current');
        $startDt = $request->input('start_date');
        $endDt = $request->input('end_date');

        $weatherData = $this->getWeatherData($location, $type, $startDt, $endDt);

        return response()->json(['data' => $weatherData]);
    }

    /**
     * Get the weather data.
     *
     * @param string $location
     * @param string $type
     * @param string|null $startDt
     * @param string|null $endDt
     * @return array
     */
    private function getWeatherData($location, $type, $startDt = null, $endDt = null)
    {
        return match ($type) {
            'current' => $this->getCurrentWeather($location),
            'forecast' => $this->getForecastWeather($location),
            'history' => $this->getHistoricalWeather($location, $startDt, $endDt),
            default => [],
        };
    }

    /**
     * Get the weather details.
     *
     * @param array $weatherData
     * @return array
     */
    private function formatWeatherData(array $weatherData)
    {
        return [
            'date' => jdate($weatherData['date'])->format('Y/m/d'),
            'mintemp_c' => number_format($weatherData['day']['mintemp_c'], 2),
            'avgtemp_c' => number_format($weatherData['day']['avgtemp_c'], 2),
            'maxtemp_c' => number_format($weatherData['day']['maxtemp_c'], 2),
            'condition' => $weatherData['day']['condition']['text'],
            'icon' => $weatherData['day']['condition']['icon'],
            'maxwind_kph' => number_format($weatherData['day']['maxwind_kph'], 2),
            'humidity' => number_format($weatherData['day']['avghumidity'], 2),
            'dewpoint_c' => number_format(collect($weatherData['hour'])->avg('dewpoint_c'), 2),
            'cloud' => number_format(collect($weatherData['hour'])->avg('cloud'), 2),
        ];
    }

    /**
     * Get the seven day forecast.
     *
     * @param string $location
     * @return array
     */
    private function getForecastWeather($location)
    {
        $weatherData = weather_api()->forecast($location, 14);
        $forecastDays = $weatherData['forecast']['forecastday'];

        return array_map([$this, 'formatWeatherData'], $forecastDays);
    }

    /**
     * Get the current weather.
     *
     * @param string $location
     * @return array
     */
    private function getCurrentWeather($location)
    {
        $weatherData = weather_api()->current($location);

        return [
            'last_updated' => jdate($weatherData['current']['last_updated'])->format('Y/m/d H:i:s'),
            'temp_c' => number_format($weatherData['current']['temp_c'], 2),
            'condition' => $weatherData['current']['condition']['text'],
            'icon' => $weatherData['current']['condition']['icon'],
            'wind_kph' => number_format($weatherData['current']['wind_kph'], 2),
            'humidity' => number_format($weatherData['current']['humidity'], 2),
            'dewpoint_c' => number_format($weatherData['current']['dewpoint_c'], 2),
            'cloud' => number_format($weatherData['current']['cloud'], 2),
        ];
    }

    /**
     * Get the historical weather.
     *
     * @param string $location
     * @param string $startDt
     * @param string $endDt
     * @return array
     */
    private function getHistoricalWeather($location, $startDt, $endDt)
    {
        $weatherData = weather_api()->history($location, $startDt, $endDt);
        $forecastDays = $weatherData['forecast']['forecastday'];

        return array_map([$this, 'formatWeatherData'], $forecastDays);
    }
}
