<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActiveTractorResource;
use App\Http\Resources\TractorTaskResource;
use App\Models\Tractor;
use Illuminate\Http\Request;
use App\Models\Farm;
use App\Services\ActiveTractorService;
use App\Services\TractorPathService;
use App\Services\Tractor\TractorWorkTimeDetectionService;

class ActiveTractorController extends Controller
{
    public function __construct(
        private ActiveTractorService $activeTractorService,
        private TractorPathService $tractorPathService,
        private TractorWorkTimeDetectionService $tractorWorkTimeDetectionService
    ) {}

    /**
     * Get active tractors for the farm
     *
     * @param Farm $farm
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Farm $farm)
    {
        $tractors = $farm->tractors()->active()->with('gpsDevice')->get();

        // Calculate work times for each tractor using the dedicated service
        $tractors = $this->tractorWorkTimeDetectionService->detectWorkTimesForTractors($tractors);

        return ActiveTractorResource::collection($tractors);
    }

    /**
     * Get traveled path for a specific tractor
     *
     * @param Request $request
     * @param Tractor $tractor
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\StreamedResponse
     */
    public function getPath(Request $request, Tractor $tractor)
    {
        $request->validate([
            'date' => 'required|shamsi_date'
        ]);

        $date = jalali_to_carbon($request->date);

        // Fallback to regular response for backward compatibility
        return $this->tractorPathService->getTractorPath($tractor, $date);
    }


    /**
     * Get timings of a specific tractor
     *
     * @param Request $request
     * @param Tractor $tractor
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTimings(Request $request, Tractor $tractor)
    {
        $request->validate([
            'date' => 'required|shamsi_date'
        ]);

        $date = jalali_to_carbon($request->date);
        return $this->activeTractorService->getTractorTimings($tractor, $date);
    }

    /**
     * Get current task of a specific tractor
     *
     * @param Tractor $tractor
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCurrentTask(Tractor $tractor)
    {
        $task = $tractor->tasks()->with(['operation', 'taskable'])->started()->first();
        return $task ?
            new TractorTaskResource($task)
            : response()->json(['data' => []]);
    }

    /**
     * Get performance of a specific tractor
     *
     * @param Request $request
     * @param Tractor $tractor
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPerformance(Request $request, Tractor $tractor)
    {
        $request->validate([
            'date' => 'required|shamsi_date'
        ]);

        $date = jalali_to_carbon($request->date);
        $performance = $this->activeTractorService->getTractorPerformance($tractor, $date);

        return response()->json(['data' => $performance]);
    }

    /**
     * Get weekly efficiency chart for a specific tractor
     *
     * @param Tractor $tractor
     * @return \Illuminate\Http\JsonResponse
     */
    public function getWeeklyEfficiencyChart(Tractor $tractor)
    {
        $chartData = $this->activeTractorService->getWeeklyEfficiencyChart($tractor);

        return response()->json(['data' => $chartData]);
    }

    /**
     * Get working tractors for the farm
     *
     * @param Farm $farm
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function getWorkingTractors(Farm $farm)
    {
        $tractors = $farm->tractors()->working()->with(['gpsDevice', 'driver'])->get();

        return response()->json([
            'data' => $tractors->map(function ($tractor) {
                return [
                    'id' => $tractor->id,
                    'name' => $tractor->name,
                    'status' => $tractor->is_working,
                ];
            }),
        ]);
    }
}
