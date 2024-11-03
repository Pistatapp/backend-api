<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use Illuminate\Http\Request;

class FrostbiteController extends Controller
{
    /**
     * Estimate the frostbite risk for the farm.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function estimate(Request $request, Farm $farm)
    {
        $request->validate([
            'start_dt' => 'required|date',
            'end_dt' => 'required|date',
            'automated' => 'nullable|boolean',
        ]);

        $startDt = jalali_to_carbon($request->start_dt);
        $endDt = jalali_to_carbon($request->end_dt);

        $days = $startDt->diffInDays($endDt);

        $data = weather_api()->forecast($farm->center, $days);

        $response = collect($data['forecast']['forecastday'])
            ->map(function ($day) {
                $temperature = $day['day']['mintemp_c'];
                return [
                    'date' => jdate($day['date'])->format('Y/m/d'),
                    'temperature' => intval($temperature),
                    'warning' => $temperature <= 0,
                ];
            });

        return response()->json(['data' => $response]);
    }
}
