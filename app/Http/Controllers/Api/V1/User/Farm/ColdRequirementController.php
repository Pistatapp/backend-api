<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Http\Controllers\Controller;
use App\Http\Resources\CropResource;
use App\Models\Farm;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;

class ColdRequirementController extends Controller
{
    public function getFarmCrop(Farm $farm)
    {
        return new CropResource($farm->crop);
    }

    public function calculate(Request $request, Farm $farm)
    {
        $request->validate(([
            'method' => 'required|string|in:method1,method2',
            'start_dt' => 'required|date',
            'end_dt' => 'required|date',
            'crop_id' => 'required|exists:crops,id',
            'min_temp' => 'required|numeric',
            'max_temp' => 'required|numeric',
        ]));

        $apiKey = 'e15f357e6d8d48e095a160006240106';

        $url = 'https://api.weatherapi.com/v1/history.json';

        $query = http_build_query([
            'key' => $apiKey,
            'q' => $farm->center,
            'dt' => jalali_to_carbon($request->start_dt)->format('Y-m-d'),
            'end_dt' => jalali_to_carbon($request->end_dt)->format('Y-m-d'),
        ]);

        $response = Http::get($url, $query);

        if ($response->failed()) {
            return response()->json($response->json(), $response->status());
        }

        $data = $response->json();

        if ($request->method === 'method1') {
            $coldRequirement = collect($data['forecast']['forecastday'])->sum(function ($day) {
                return collect($day['hour'])->filter(function ($hour) {
                    $temp = $hour['temp_c'];
                    $min_temp = request()->input('min_temp', 0);
                    $max_temp = request()->input('max_temp', 7);
                    return $temp >= $min_temp && $temp <= $max_temp;
                })->count();
            });
        } else {
            //
        }

        return response()->json([
            'data' => [
                'crop' => new CropResource($farm->crop),
                'min_temp' => $request->min_temp,
                'max_temp' => $request->max_temp,
                'start_dt' => $request->start_dt,
                'end_dt' => $request->end_dt,
                'cold_requirement' => $coldRequirement,
            ],
        ]);
    }
}
