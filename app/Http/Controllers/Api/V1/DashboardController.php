<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Get the dashboard widgets data.
     *
     * @param Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function dashboardWidgets(Farm $farm)
    {
        $dashboardData = [
            'weather_forecast' => $this->getWeatherData($farm->center),
            'working_tractors' => $farm->tractors()->working()->count(),
            'working_labours' => $farm->labours()->working()->count(),
            'active_pumps' => $farm->pumps()->active()->count(),
        ];

        return response()->json(['data' => $dashboardData]);
    }

    /**
     * Get the weather data.
     *
     * @param string $location
     * @return array
     */
    private function getWeatherData($location)
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
}
