<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\FrostbitePredictionRequest;
use App\Models\Farm;
use Illuminate\Http\Request;

class FrostbiteCalculationController extends Controller
{
    /**
     * Estimate the frostbite risk for the farm.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function estimate(FrostbitePredictionRequest $request, Farm $farm)
    {
        if($request->type === 'normal') {
            $days = $request->end_dt->diffInDays($request->start_dt);
            $data = weather_api()->forecast($farm->center, $days);
            $response = $this->estimateFrostbiteRisk($data);
        } else {
            $data = weather_api()->forecast($farm->center, 1);
            $response = $this->estimateRadiationalFrostbiteRisk($data);
        }

        return response()->json(['data' => $response]);
    }

    /**
     * Estimate the frostbite risk for the farm.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    private function estimateFrostbiteRisk($data)
    {
        return collect($data['forecast']['forecastday'])
            ->map(function ($day) {
                $minTemp = $day['day']['mintemp_c'];

                return [
                    'date' => jdate($day['date'])->format('Y/m/d'),
                    'temperature' => intval($minTemp),
                    'warning' => $minTemp <= 0,
                ];
            });
    }

    /**
     * Estimate the radiational frostbite risk for the farm.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    private function estimateRadiationalFrostbiteRisk($data)
    {
        return collect($data['forecast']['forecastday'])
            ->map(function ($day) {
                $averageDewPoint = collect($day['hour'])->avg('dewpoint_c');
                $avgTemp = $day['day']['avgtemp_c'];

                $temp1 = (0.18 * $avgTemp) + (0.083 * $averageDewPoint) - 2.33;
                $temp2 = (0.21 * $avgTemp) + 2.3;

                return [
                    'date' => jdate($day['date'])->format('Y/m/d'),
                    'temperature' => intval($avgTemp),
                    'warning' => $temp1 < 0 || $temp2 < 0,
                ];
            });
    }

    /**
     * Send notification for the frostbite risk.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendNotification(Request $request, Farm $farm)
    {
        $request->validate([
            'type' => ['required', 'in:normal,radiational'],
            'notify' => ['required', 'boolean'],
        ]);

        $farm->frostbitRisks()->updateOrCreate(
            ['type' => $request->type],
            ['notify' => $request->notify]
        );

        return response()->noContent();
    }
}
