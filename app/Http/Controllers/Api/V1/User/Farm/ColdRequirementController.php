<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\CalculateColdRequirementRequest;
use App\Models\Farm;
use Illuminate\Http\Request;

class ColdRequirementController extends Controller
{
    /**
     * Calculate the cold requirement for the farm.
     *
     * @param \Illuminate\Http\ColdRequirementRequest $request
     * @param \App\Models\Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(CalculateColdRequirementRequest $request, Farm $farm)
    {
        $data = weather_api()->history($farm->center, $request->start_dt, $request->end_dt);

        $minTemp = $request->input('min_temp', 0);
        $maxTemp = $request->input('max_temp', 7);
        $method = $request->input('method', 'method1');

        $coldRequirement = $this->calculateColdRequirement($data, $minTemp, $maxTemp, $method);

        return response()->json([
            'data' => [
                'min_temp' => $minTemp,
                'max_temp' => $maxTemp,
                'start_dt' => jdate($request->start_dt)->format('Y/m/d'),
                'end_dt' => jdate($request->end_dt)->format('Y/m/d'),
                'num_days' => count($data['forecast']['forecastday']),
                'satisfied_cp' => $coldRequirement,
            ],
        ]);
    }

    /**
     * Calculate the cold requirement for the farm.
     *
     * @param array $data
     * @param int $minTemp
     * @param int $maxTemp
     * @param string $method
     * @return int
     */
    private function calculateColdRequirement(array $data, int $minTemp, int $maxTemp, string $method): int
    {
        return $method === 'method1'
            ? $this->calculateColdRequirementMethod1($data, $minTemp, $maxTemp)
            : $this->calculateColdRequirementMethod2($data);
    }

    /**
     * Calculate the cold requirement for the farm using method 1.
     *
     * @param array $data
     * @param int $minTemp
     * @param int $maxTemp
     * @return int
     */
    private function calculateColdRequirementMethod1(array $data, int $minTemp, int $maxTemp): int
    {
        return collect($data['forecast']['forecastday'])->sum(function ($day) use ($minTemp, $maxTemp) {
            return collect($day['hour'])->filter(function ($hour) use ($minTemp, $maxTemp) {
                $temp = $hour['temp_c'];
                return $temp >= $minTemp && $temp <= $maxTemp;
            })->count();
        });
    }

    /**
     * Calculate the cold requirement for the farm using method 2.
     *
     * @param array $data
     * @param int $minTemp
     * @param int $maxTemp
     * @return int
     */
    private function calculateColdRequirementMethod2(array $data): int
    {
        $e0 = 4153.5;
        $e1 = 12888.8;
        $a0 = 139500;
        $a1 = 2567000000000000000;
        $slp = 1.6;
        $tetmlt = 277;
        $aa = $a0 / $a1;
        $ee = $e1 - $e0;

        $tempC = [];
        $tempK = [];
        $xi = [];
        $xs = [];
        $ak1 = [];
        $InterS = [];
        $InterE = [];
        $delt = [];
        $Portions = [];

        foreach ($data['forecast']['forecastday'] as $day) {
            foreach ($day['hour'] as $hour) {
                $tempC[] = $hour['temp_c'];
            }
        }

        foreach ($tempC as $i => $temp) {
            $tempK[$i] = 273 + $temp;
            $xi[$i] = exp($slp * $tetmlt * ($tempK[$i] - $tetmlt) / $tempK[$i]) / (1 + exp($slp * $tetmlt * ($tempK[$i] - $tetmlt) / $tempK[$i]));
            $xs[$i] = $aa * exp($ee / $tempK[$i]);
            $ak1[$i] = $a1 * exp(-$e1 / $tempK[$i]);

            if ($i == 0) {
                $InterS[$i] = 0;
                $InterE[$i] = $xs[$i] - ($xs[$i] - $InterS[$i]) * exp(-$ak1[$i]);
                $delt[$i] = $InterE[$i] < 1 ? 0 : $InterE[$i] * $xi[$i];
                $Portions[$i] = 0; // Set the first row value to 0
            } else {
                $InterS[$i] = $InterE[$i - 1] < 1 ? $InterE[$i - 1] : $InterE[$i - 1] - $InterE[$i - 1] * $xi[$i - 1];
                $InterE[$i] = $xs[$i] - ($xs[$i] - $InterS[$i]) * exp(-$ak1[$i]);
                $delt[$i] = $InterE[$i] < 1 ? 0 : $InterE[$i] * $xi[$i];
                $Portions[$i] = round($Portions[$i - 1] + $delt[$i - 1], 0);
            }
        }

        return max($Portions);
    }
}
