<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\BlightCalculationRequest;
use App\Models\Farm;
use Illuminate\Http\Request;

class BlightCalculationController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(BlightCalculationRequest $request, Farm $farm)
    {
        $this->authorize('view', $farm);

        $location = $farm->center;
        $data = $this->fetchWeatherData($location, $request->start_dt, $request->end_dt);
        $totalBaseTemp = $this->calculateTotalBaseTemp($data, $request->min_temp);

        return response()->json([
            'data' => [
                'satisfied_day_degree' => number_format($totalBaseTemp, 2),
                'remaining_day_degree' => number_format($request->development_total - $totalBaseTemp, 2),
            ],
        ]);
    }

    /**
     * Fetch weather data from the weather API.
     *
     * @param string $location
     * @param string $startDt
     * @param string $endDt
     * @return array
     */
    private function fetchWeatherData($location, $startDt, $endDt)
    {
        return weather_api()->history($location, $startDt, $endDt);
    }

    /**
     * Calculate the total base temperature.
     *
     * @param array $data
     * @param int $minTemp
     * @return float
     */
    private function calculateTotalBaseTemp($data, $minTemp)
    {
        return collect($data['forecast']['forecastday'])
            ->map(function ($day) use ($minTemp) {
                $avgTemp = $day['day']['avgtemp_c'];
                $baseTemp = $avgTemp - $minTemp;

                return $baseTemp > 0 ? $baseTemp : 0;
            })->sum();
    }
}
