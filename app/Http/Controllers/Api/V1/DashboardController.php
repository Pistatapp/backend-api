<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActiveWorkerWidgetResource;
use App\Models\Farm;
use App\Services\ActiveWorkerService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function __construct(
        private ActiveWorkerService $activeWorkerService
    ) {}

    /**
     * Get the dashboard widgets data.
     *
     * @param Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function dashboardWidgets(Farm $farm)
    {
        $dashboardData = [
            'weather_forecast' => $this->getWeatherData($farm->center),
            'working_tractors' => $farm->tractors()->working()->count(),
            'working_employees' => $farm->employees()->working()->count(),
            'active_pumps' => $farm->pumps()->active()->count(),
        ];

        return response()->json(['data' => $dashboardData]);
    }

    /**
     * Get active workers for dashboard widget
     *
     * @param Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function getActiveWorkers(Farm $farm)
    {
        $activeWorkers = $this->activeWorkerService->getActiveWorkers($farm);

        // Format for widget display
        $formattedWorkers = $activeWorkers->map(function ($worker) {
            // Get entry time from today's session
            $session = \App\Models\WorkerAttendanceSession::where('employee_id', $worker['id'])
                ->where('date', Carbon::today()->toDateString())
                ->where('status', 'in_progress')
                ->first();

            $entryTime = $session?->entry_time;
            $workingHours = $entryTime ? Carbon::now()->diffInHours($entryTime) : 0;

            return [
                'id' => $worker['id'],
                'name' => $worker['name'],
                'entry_time' => $entryTime?->toIso8601String(),
                'working_hours' => $workingHours,
            ];
        });

        return response()->json(['data' => ActiveWorkerWidgetResource::collection($formattedWorkers)]);
    }

    /**
     * Get the weather data.
     *
     * @param string $location
     * @return array
     */
    private function getWeatherData($location)
    {
        $weatherData = weather_api()->current($location);

        return [
            'last_updated' => jdate($weatherData['current']['last_updated'])->format('Y/m/d H:i:s'),
            'temp_c' => number_format($weatherData['current']['temp_c'], 2),
            'condition' => $weatherData['current']['condition']['text'],
            'icon' => $weatherData['current']['condition']['icon'],
            'wind_kph' => number_format($weatherData['current']['wind_kph'], 2),
            'humidity' => number_format($weatherData['current']['humidity'], 2),
            'dewpoint_c' => number_format($weatherData['current']['dewpoint_c'], 2),
            'cloud' => number_format($weatherData['current']['cloud'], 2),
        ];
    }
}
