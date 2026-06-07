<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\BlightCalculationRequest;
use App\Models\Farm;
use App\Services\WeatherForecastService;

class BlightCalculationController extends Controller
{
    public function __construct(
        private WeatherForecastService $weatherForecastService,
    ) {}
    /**
     * Handle the incoming request.
     */
    public function __invoke(BlightCalculationRequest $request, Farm $farm)
    {
        $this->authorize('view', $farm);

        $data = $this->weatherForecastService->historyAsForecastDays(
            $farm->center,
            $request->start_dt,
            $request->end_dt
        );
        $totalBaseTemp = $this->calculateTotalBaseTemp($data, $request->min_temp);

        return response()->json([
            'data' => [
                'satisfied_day_degree' => number_format($totalBaseTemp, 2),
                'remaining_day_degree' => number_format($request->development_total - $totalBaseTemp, 2),
            ],
        ]);
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
