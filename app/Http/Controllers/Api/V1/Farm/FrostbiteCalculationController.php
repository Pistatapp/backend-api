<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FrostbiteCalculationController extends Controller
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
        $request->validate(['type' => ['required', 'in:normal,radiational']]);

        if ($request->type === 'normal') {
            $data = weather_api()->forecast($farm->center, 14);
            $response = $this->estimateFrostbiteRisk($data);
        } else {
            $data = weather_api()->forecast($farm->center, 2);
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
                    'temperature' => number_format($minTemp, 2),
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
        $day = $data['forecast']['forecastday'][1];
        $maxDewPoint = collect($day['hour'])->max('dewpoint_c');
        $maxCloudiness = collect($day['hour'])->max('cloud');
        $avgTemp = $day['day']['avgtemp_c'];

        $temp1 = (0.18 * $avgTemp) + (0.083 * $maxDewPoint) - 2.33;
        $temp2 = (0.21 * ($avgTemp + 0.4)) + 2.7;

        return [
            'date' => jdate($day['date'])->format('Y/m/d'),
            'maxwind_kph' => $day['day']['maxwind_kph'],
            'dewpoint_c' => number_format($maxDewPoint, 2),
            'cloud' => number_format($maxCloudiness, 2),
            'avgtemp_c' => number_format($avgTemp, 2),
            'mintemp_c' => $day['day']['mintemp_c'],
            'warning' => $temp1 < 0 || $temp2 < 0,
            'T1' => round($temp1, 2),
            'T2' => round($temp2, 2),
        ];
    }

    /**
     * Get the frostbite notification settings for the farm.
     *
     * @param \App\Models\Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNotification(Farm $farm)
    {
        request()->validate(['type' => ['required', 'in:normal,radiational']]);

        $notification = $farm->frostbitRisks()->where('type', request('type'))->first();

        return response()->json(['data' => [
            'type' => request('type'),
            'notify' => $notification ? $notification->notify : false,
        ]]);
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

        $notification = $farm->frostbitRisks()->where('type', $request->type)->first();

        return response()->json(['data' => $notification], JsonResponse::HTTP_CREATED);
    }
}
