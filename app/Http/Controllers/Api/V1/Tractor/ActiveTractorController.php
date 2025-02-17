<?php

namespace App\Http\Controllers\Api\V1\tractor;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivetractorResource;
use App\Models\Tractor;
use Illuminate\Http\Request;
use App\Http\Resources\PointsResource;
use App\Models\Farm;

class ActiveTractorController extends Controller
{

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
        $startWorkingTime = count($reports) > 0 ? $reports->where('is_starting_point', 1)->first() : null;

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
                'points' => PointsResource::collection($reports),
            ]
        ]);
    }
}
