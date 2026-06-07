<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\WeatherForecastRequest;
use App\Models\Farm;
use App\Services\WeatherForecastService;

class WeatherForecastController extends Controller
{
    public function __construct(
        private WeatherForecastService $weatherForecastService,
    ) {}

    /**
     * Get the weather forecast for the farm.
     *
     * @param WeatherForecastRequest $request
     * @param Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(WeatherForecastRequest $request, Farm $farm)
    {
        $this->authorize('view', $farm);

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
            'current' => $this->weatherForecastService->currentDay($location),
            'forecast' => $this->weatherForecastService->forecastDays($location),
            'history' => $this->weatherForecastService->historyDays($location, $startDt, $endDt),
            default => [],
        };
    }
}
