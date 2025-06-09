<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActiveTractorResource;
use App\Http\Resources\PointsResource;
use App\Models\Tractor;
use Illuminate\Http\Request;
use App\Models\Farm;
use App\Services\TractorReportService;

class ActiveTractorController extends Controller
{
    public function __construct(
        private TractorReportService $tractorReportService
    ) {}

    /**
     * Get active tractors for the farm
     *
     * @param Farm $farm
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Farm $farm)
    {
        $tractors = $farm->tractors()->active()
            ->with(['gpsDevice', 'driver', 'startWorkingTime'])
            ->get();

        return ActiveTractorResource::collection($tractors);
    }

    /**
     * Get traveled path for a specific tractor
     *
     * @param Request $request
     * @param Tractor $tractor
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPath(Request $request, Tractor $tractor)
    {
        $request->validate([
            'date' => 'required|shamsi_date'
        ]);

        $date = jalali_to_carbon($request->date);
        $points = $this->tractorReportService->getTractorPath($tractor, $date);
        return PointsResource::collection($points);
    }

    /**
     * Get details of a specific tractor
     *
     * @param Request $request
     * @param Tractor $tractor
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDetails(Request $request, Tractor $tractor)
    {
        $request->validate([
            'date' => 'required|shamsi_date'
        ]);

        $date = jalali_to_carbon($request->date);
        $details = $this->tractorReportService->getTractorDetails($tractor, $date);

        return response()->json(['data' => $details]);
    }
}
