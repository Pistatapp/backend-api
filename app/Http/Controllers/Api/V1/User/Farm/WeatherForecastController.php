<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use Illuminate\Http\Request;

class WeatherForecastController extends Controller
{
    /**
     * Get the weather forecast for the farm.
     *
     * @param Request $request
     * @param Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(Request $request, Farm $farm)
    {
        $location = $farm->center;

        $type = $request->input('type', 'current');

        $weatherData = $this->getWeatherData($location, $type);

        return response()->json(['data' => $weatherData]);
    }

    /**
     * Get the weather data.
     *
     * @param string $location
     * @param string $type
     * @return array
     */
    private function getWeatherData($location, $type)
    {
        return $type === 'current'
            ? $this->getCurrentWeather($location)
            : $this->getSevenDayForecast($location);
    }

    /**
     * Get the seven day forecast.
     *
     * @param string $location
     * @return array
     */
    private function getSevenDayForecast($location)
    {
        $weatherData = weather_api()->forecast($location, 7);
        $forecastDays = $weatherData['forecast']['forecastday'];

        return array_map(function ($day) {
            return [
                'date' => jdate($day['date'])->format('Y/m/d'),
                'mintemp_c' => number_format($day['day']['mintemp_c'], 2),
                'maxtemp_c' => number_format($day['day']['maxtemp_c'], 2),
                'condition' => $day['day']['condition']['text'],
                'icon' => $day['day']['condition']['icon'],
                'maxwind_kph' => number_format($day['day']['maxwind_kph'], 2),
                'humidity' => number_format($day['day']['avghumidity'], 2),
                'dewpoint_c' => number_format(collect($day['hour'])->avg('dewpoint_c'), 2),
                'cloud' => number_format(collect($day['hour'])->avg('cloud'), 2),
            ];
        }, $forecastDays);
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
            'temprature' => number_format($weatherData['current']['temp_c'], 2),
            'condition' => $weatherData['current']['condition']['text'],
            'icon' => $weatherData['current']['condition']['icon'],
            'wind' => number_format($weatherData['current']['wind_kph'], 2),
            'humidity' => number_format($weatherData['current']['humidity'], 2),
            'dewpoint_c' => number_format($weatherData['current']['dewpoint_c'], 2),
            'cloud' => number_format($weatherData['current']['cloud'], 2),
        ];
    }
}
