<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActiveTractorResource;
use App\Models\Tractor;
use Illuminate\Http\Request;
use App\Models\Farm;
use App\Services\ActiveTractorService;
use App\Services\TractorPathService;
use App\Services\TractorPathStreamService;
use App\Services\TractorStartMovementTimeDetectionService;

class ActiveTractorController extends Controller
{
    public function __construct(
        private ActiveTractorService $activeTractorService,
        private TractorPathService $tractorPathService,
        private TractorPathStreamService $tractorPathStreamService,
        private TractorStartMovementTimeDetectionService $tractorStartMovementTimeDetectionService
    ) {}

    /**
     * Get active tractors for the farm
     *
     * @param Request $request
     * @param Farm $farm
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request, Farm $farm)
    {
        $request->validate([
            'date' => 'sometimes|shamsi_date'
        ]);

        $date = $request->has('date') ? jalali_to_carbon($request->date) : null;

        $tractors = $farm->tractors()->active()->with('gpsDevice', 'driver')->get();

        // Detect start movement time for all tractors using the service
        $tractors = $this->tractorStartMovementTimeDetectionService->detectStartMovementTimeForTractors($tractors, $date);

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
            'date' => 'required|shamsi_date',
            'stream' => 'sometimes|boolean'
        ]);

        $date = jalali_to_carbon($request->date);

        // Use streamed service if stream parameter is present and true
        if ($request->has('stream') && $request->boolean('stream')) {
            return $this->tractorPathStreamService->getTractorPath($tractor, $date);
        }

        return $this->tractorPathService->getTractorPath($tractor, $date);
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
