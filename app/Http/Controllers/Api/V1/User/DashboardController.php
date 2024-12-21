<?php

namespace App\Http\Controllers\Api\V1\User;

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
        $weatherData = $this->getWeatherData($farm->center);
        $workingTrucktors = $farm->trucktors()->working()->count();
        $workingLabours = $farm->labours()->working()->count();
        $activePumps = $farm->pumps()->where('is_active', true)->count();

        return response()->json(['date' => [
            'weather_forecast' => $weatherData,
            'working_trucktors' => $workingTrucktors,
            'working_labours' => $workingLabours,
            'active_pumps' => $activePumps,
        ]]);
    }

    private function getWeatherData($location)
    {
        $weatherData = weather_api()->current($location);

        return [
            'last_updated' => jdate($weatherData['current']['last_updated'])->format('Y/m/d H:i:s'),
            'tempc' => number_format($weatherData['current']['temp_c'], 2),
            'condition' => $weatherData['current']['condition']['text'],
            'icon' => $weatherData['current']['condition']['icon'],
            'wind_kph' => number_format($weatherData['current']['wind_kph'], 2),
            'humidity' => number_format($weatherData['current']['humidity'], 2),
            'dewpoint_c' => number_format($weatherData['current']['dewpoint_c'], 2),
            'cloud' => number_format($weatherData['current']['cloud'], 2),
        ];
    }
}
