<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

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
        $data = weather_api()->history($farm->center, $request->start_dt, $request->end_dt);

        $baseTemps = collect($data['forecast']['forecastday'])
            ->map(function ($day) use ($request) {
                $avgTemp = $day['day']['avgtemp_c'];
                $minTemp = $request->min_temp;
                $baseTemp = ($avgTemp - $minTemp) < 0 ? 0 : $avgTemp - $minTemp;

                return $baseTemp;
            });

        $totalBaseTemp = $baseTemps->sum();

        return response()->json([
            'data' => [
                'satisfied_day_degree' => (int)$totalBaseTemp,
                'remaining_day_degree' => (int)($totalBaseTemp - $request->developement_total),
            ],
        ]);
    }
}
