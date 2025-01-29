<?php

namespace App\Http\Controllers\Api\V1\Farm;

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
    public function __invoke(DayDegreeCalculationRequest $request, Farm $farm)
    {
        $model = getModel($request->model_type, $request->model_id);

        $data = weather_api()->history($farm->center, $request->start_dt, $request->end_dt);

        return response()->json([
            'data' => collect($data['forecast']['forecastday'])
                ->map(function ($day) use ($model, $request) {
                    $avgTemp = $day['day']['avgtemp_c'];
                    $minTemp = $request->min_temp;
                    $satisfiedDayDegree = max(0, $avgTemp - $minTemp);

                    return [
                        'model_id' => $model->id,
                        'model_type' => $request->model_type,
                        'mintemp_c' => $minTemp,
                        'maxtemp_c' => $request->max_temp,
                        'avgtemp_c' => number_format($avgTemp, 2),
                        'satisfied_day_degree' => number_format($satisfiedDayDegree, 2),
                        'date' => jdate($day['date'])->format('Y/m/d'),
                    ];
                }),
        ]);
    }
}
