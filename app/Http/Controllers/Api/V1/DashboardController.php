<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActiveLabourWidgetResource;
use App\Models\AttendanceSession;
use App\Models\Farm;
use App\Services\ActiveUserAttendanceService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function __construct(
        private ActiveUserAttendanceService $activeUserAttendanceService
    ) {}

    /**
     * Get the dashboard widgets data.
     *
     * @param Request $request
     * @param Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function dashboardWidgets(Request $request, Farm $farm)
    {
        $this->authorize('view', $farm);

        $workingAttendanceCount = AttendanceSession::whereHas('user.attendanceTracking', function ($query) use ($farm) {
            $query->where('farm_id', $farm->id)->where('enabled', true);
        })
            ->whereDate('date', Carbon::today())
            ->where('status', 'in_progress')
            ->distinct()
            ->count('user_id');

        $dashboardData = [
            'weather_forecast' => $this->getWeatherData($farm->center),
            'working_tractors' => $farm->tractors()->working()->count(),
            'working_labours' => $workingAttendanceCount,
            'active_pumps' => $farm->pumps()->active()->count(),
        ];

        return response()->json(['data' => $dashboardData]);
    }

    /**
     * Get active users with attendance for dashboard widget
     *
     * @param Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function getActiveLabours(Farm $farm)
    {
        $this->authorize('view', $farm);

        $activeUsers = $this->activeUserAttendanceService->getActiveUsers($farm);

        $formatted = $activeUsers->map(function ($userData) {
            $session = AttendanceSession::where('user_id', $userData['id'])
                ->where('date', Carbon::today()->toDateString())
                ->where('status', 'in_progress')
                ->first();

            $entryTime = $session?->entry_time;
            $workingHours = $entryTime ? Carbon::now()->diffInHours($entryTime) : 0;

            return [
                'id' => $userData['id'],
                'name' => $userData['name'],
                'entry_time' => $entryTime?->toIso8601String(),
                'working_hours' => $workingHours,
            ];
        });

        return response()->json(['data' => ActiveLabourWidgetResource::collection($formatted)]);
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
