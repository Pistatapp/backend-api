<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActiveTractorResource;
use App\Models\Tractor;
use Illuminate\Http\Request;
use App\Models\Farm;
use App\Services\ActiveTractorService;
use App\Services\TractorPathStreamService;
use App\Services\TractorPathPaginationService;
use App\Services\TractorStartMovementTimeDetectionService;
use App\Services\TractorLastKnownPositionService;
use Carbon\Carbon;
use InvalidArgumentException;

class ActiveTractorController extends Controller
{
    public function __construct(
        private ActiveTractorService $activeTractorService,
        private TractorPathStreamService $tractorPathStreamService,
        private TractorPathPaginationService $tractorPathPaginationService,
        private TractorStartMovementTimeDetectionService $tractorStartMovementTimeDetectionService,
        private TractorLastKnownPositionService $tractorLastKnownPositionService
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

        $date = $request->has('date') ? jalali_to_carbon($request->date) : Carbon::today();

        $tractors = $farm->tractors()->active()->with('gpsDevice', 'driver')->get();

        // Detect start movement time for all tractors using the service
        $tractors = $this->tractorStartMovementTimeDetectionService->detectStartMovementTimeForTractors($tractors, $date);

        // Enrich the membership result only through the bounded latest-only
        // service. This must never call the full historical resolver.
        $assignedImeis = $tractors->mapWithKeys(function (Tractor $tractor): array {
            return [(int) $tractor->getKey() => (string) ($tractor->gpsDevice?->imei ?? '')];
        })->all();
        $assignedSince = $tractors->mapWithKeys(function (Tractor $tractor): array {
            $createdAt = $tractor->gpsDevice?->created_at;

            return [(int) $tractor->getKey() => $createdAt ? (string) $createdAt : ''];
        })->all();
        $positionResolutions = $this->tractorLastKnownPositionService->resolveForTractors(
            $tractors->modelKeys(),
            $assignedImeis,
            $farm->coordinates,
            $assignedSince,
        );
        $tractors->each(function (Tractor $tractor) use ($positionResolutions): void {
            $resolution = $positionResolutions[(int) $tractor->getKey()] ?? [];
            $tractor->setAttribute('last_known_position', $resolution['last_known_position'] ?? null);
            $tractor->setAttribute('last_known_position_state', $resolution['last_known_position_state'] ?? 'LOCATION_UNKNOWN');
            $tractor->setAttribute('latest_device_position', $resolution['latest_device_position'] ?? null);
        });

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
        ]);

        $date = jalali_to_carbon($request->date);

        return $this->tractorPathStreamService->getTractorPath($tractor, $date);
    }

    /**
     * Versioned cursor-paginated historical path. The authorization policy is
     * intentionally the same as the legacy endpoint.
     */
    public function getPathV2(Request $request, Tractor $tractor)
    {
        $validated = $request->validate([
            'date' => 'required|shamsi_date',
            'cursor' => 'nullable|string|max:8192',
            'limit' => 'nullable|integer|min:1|max:1000',
        ]);

        try {
            return response()->json($this->tractorPathPaginationService->page(
                $tractor,
                jalali_to_carbon($validated['date']),
                $validated['cursor'] ?? null,
                (int) ($validated['limit'] ?? config('trajectory.historical_path.default_page_size', 1000)),
            ));
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'PATH_CURSOR_INVALID',
            ], 422);
        }
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
