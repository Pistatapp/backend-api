<?php

namespace App\Http\Controllers\Api\V1\User\Phonology;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use Illuminate\Http\Request;

class DayDegreeCalculationController extends Controller
{
    /**
     * Calculate the day degree for a farm.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function calculate(Request $request, Farm $farm)
    {
        $this->validateRequest($request);

        $model = $this->getModel($request->model_type, $request->model_id);

        $data = $this->fetchWeatherData($farm->center, $request->start_dt, $request->end_dt);

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
     * Validate the request.
     *
     * @param \Illuminate\Http\Request $request
     * @return void
     */
    private function validateRequest(Request $request)
    {
        $request->validate([
            'model_type' => 'required|string|in:crop_type,pest',
            'model_id' => 'required|integer',
            'start_dt' => 'required|date',
            'end_dt' => 'required|date',
            'min_temp' => 'required|numeric',
            'max_temp' => 'required|numeric',
            'developement_total' => 'nullable|numeric',
        ]);
    }

    /**
     * Get the model by type and id.
     *
     * @param string $modelType
     * @param int $modelId
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function getModel($modelType, $modelId)
    {
        // Assuming getModel is a helper function or method in the same class
        return getModel($modelType, $modelId);
    }

    /**
     * Fetch weather data from the API.
     *
     * @param array $location
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    private function fetchWeatherData($location, $startDate, $endDate)
    {
        return weather_api()->history($location, $startDate, $endDate);
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

            if ($temp >= 0) {
                $averageTemperatures[] = $temp;
            }
        }

        return $averageTemperatures;
    }
}
