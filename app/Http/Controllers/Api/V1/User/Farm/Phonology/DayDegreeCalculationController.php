<?php

namespace App\Http\Controllers\Api\V1\User\Farm\Phonology;

use App\Http\Controllers\Controller;
use App\Http\Requests\DayDegreeCalculationRequest;
use App\Models\Farm;
use Illuminate\Http\Request;

class DayDegreeCalculationController extends Controller
{
    /**
     * Calculate the day degree for a farm.
     *
     * @param \App\Http\Requests\DayDegreeCalculationRequest $request
     * @param \App\Models\Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function calculate(DayDegreeCalculationRequest $request, Farm $farm)
    {
        $model = getModel($request->model_type, $request->model_id);

        $data = weather_api()->history($farm->center, $request->start_dt, $request->end_dt);

        $averageTemperatures = $this->calculateAverageTemperatures(
            $data['forecast']['forecastday'],
            $request->min_temp,
        );

        $developementTotal = $request->developement_total ?? $model->standard_day_degree;

        $adjustedTemperaturesSum = array_sum($averageTemperatures);

        return response()->json([
            'satisfied_day_temperature' => number_format($adjustedTemperaturesSum, 2),
            'remaining_day_temperature' => number_format($adjustedTemperaturesSum - $developementTotal, 2),
        ]);
    }

    /**
     * Calculate the average temperatures from the forecast data.
     *
     * @param array $forecastDays
     * @param float $minTemp
     * @param float $maxTemp
     * @return array
     */
    private function calculateAverageTemperatures(array $forecastDays, $minTemp)
    {
        $averageTemperatures = [];

        foreach ($forecastDays as $day) {
            $temp = $day['day']['avgtemp_c'] - $minTemp;

            if ($temp < 0) continue;

            $averageTemperatures[] = $temp;
        }

        return $averageTemperatures;
    }
}
