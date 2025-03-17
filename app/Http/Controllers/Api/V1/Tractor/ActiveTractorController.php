<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActiveTractorResource;
use App\Models\Tractor;
use Illuminate\Http\Request;
use App\Http\Resources\PointsResource;
use App\Models\Farm;
use App\Http\Resources\TractorTaskResource;
use App\Services\KalmanFilter;

class ActiveTractorController extends Controller
{
    private $kalmanFilter;

    public function __construct()
    {
        $this->kalmanFilter = new KalmanFilter();
    }

    /**
     * Get active tractors for the farm
     *
     * @param Farm $farm
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Farm $farm)
    {
        $tractors = Tractor::whereBelongsTo($farm)->whereHas('gpsDevice')
            ->whereHas('driver')
            ->with(['gpsDevice', 'driver', 'gpsReports' => function ($query) {
                $query->whereDate('date_time', today())->latest('date_time')->limit(1);
            }])->get();

        return ActiveTractorResource::collection($tractors);
    }

    /**
     * Get reports for a sepcific tractor
     *
     * @param Request $request
     * @param Tractor $tractor
     * @return \Illuminate\Http\JsonResponse
     */
    public function reports(Request $request, Tractor $tractor)
    {
        $request->validate([
            'date' => 'required|shamsi_date'
        ]);

        $date = jalali_to_carbon($request->date);

        $dailyReport = $tractor->gpsDailyReports()->where('date', $date)->first();

        $reports = $tractor->gpsReports()->whereDate('date_time', $date)->orderBy('date_time')->get();

        // Apply Kalman filter to smooth the GPS coordinates
        $filteredReports = $reports->map(function ($report) {
            $filtered = $this->kalmanFilter->filter($report->latitude, $report->longitude);
            $report->latitude = $filtered['latitude'];
            $report->longitude = $filtered['longitude'];
            return $report;
        });

        $startWorkingTime = count($reports) > 0 ? $reports->where('is_starting_point', 1)->first() : null;
        $currentTask = $tractor->tasks()->where('date', $date)
            ->with('operation', 'field', 'creator')
            ->latest()->first();

        return response()->json([
            'data' => [
                'id' => $tractor->id,
                'name' => $tractor->name,
                'speed' => $reports->last()->speed ?? 0,
                'status' => $reports->last()->status ?? 0,
                'start_working_time' => $startWorkingTime ? $startWorkingTime->date_time->format('H:i:s') : '00:00:00',
                'traveled_distance' => number_format($dailyReport->traveled_distance ?? 0, 2),
                'work_duration' => gmdate('H:i:s', $dailyReport->work_duration ?? 0),
                'stoppage_count' => $dailyReport->stoppage_count ?? 0,
                'stoppage_duration' => gmdate('H:i:s', $dailyReport->stoppage_duration ?? 0),
                'efficiency' => number_format($dailyReport->efficiency ?? 0, 2),
                'points' => PointsResource::collection($filteredReports),
                'current_task' => new TractorTaskResource($currentTask)
            ]
        ]);
    }
}
