<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\FilterIrrigationReportsRequest;
use App\Http\Requests\StoreIrrigationRequest;
use App\Http\Requests\UpdateIrrigationRequest;
use App\Http\Resources\IrrigationResource;
use App\Models\Farm;
use App\Models\Irrigation;
use App\Models\Plot;
use App\Services\IrrigationReportService;
use Illuminate\Http\Request;

class IrrigationController extends Controller
{
    public function __construct(
        private IrrigationReportService $irrigationReportService
    ) {
        $this->authorizeResource(Irrigation::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Farm $farm)
    {
        $status = $request->query('status', 'all');

        $irrigations = Irrigation::whereBelongsTo($farm);

        // Handle date filtering
        if ($request->has('date_range')) {
            // Parse date range (format: "start_date,end_date")
            $dateRange = explode(',', $request->query('date_range'));
            if (count($dateRange) === 2) {
                $startDate = jalali_to_carbon(trim($dateRange[0]));
                $endDate = jalali_to_carbon(trim($dateRange[1]));
                $irrigations->whereBetween('start_date', [$startDate, $endDate]);
            }
        } elseif ($request->has('date')) {
            // Single date filter
            $date = jalali_to_carbon($request->query('date'));
            $irrigations->whereDate('start_date', $date);
        } else {
            // Default to today
            $irrigations->whereDate('start_date', today());
        }

        $irrigations = $irrigations->when($status !== 'all', function ($query) use ($status) {
                $query->filter($status);
            })
            ->with(['plots', 'valves'])
            ->withCount('plots')
            ->latest()
            ->get();

        return IrrigationResource::collection($irrigations);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreIrrigationRequest $request, Farm $farm)
    {
        $irrigation = $farm->irrigations()->create([
            'labour_id' => $request->labour_id,
            'pump_id' => $request->pump_id,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'created_by' => $request->user()->id,
            'note' => $request->note,
        ]);

        $irrigation->plots()->attach($request->plots);
        $irrigation->valves()->attach($request->valves);

        return new IrrigationResource($irrigation);
    }

    /**
     * Display the specified resource.
     */
    public function show(Irrigation $irrigation)
    {
        $irrigation->load(['labour', 'valves', 'plots', 'pump']);

        return new IrrigationResource($irrigation);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateIrrigationRequest $request, Irrigation $irrigation)
    {
        $irrigation->update([
            'labour_id' => $request->labour_id,
            'pump_id' => $request->pump_id,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'note' => $request->note,
        ]);

        $irrigation->plots()->sync($request->plots);
        $irrigation->valves()->sync($request->valves);

        return new IrrigationResource($irrigation->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Irrigation $irrigation)
    {
        $irrigation->delete();

        return response()->noContent();
    }

    /**
     * Get irrigations for a plot.
     *
     * @param  \App\Models\Plot  $plot
     * @return \App\Http\Resources\IrrigationResource
     */
    public function getIrrigationsForPlot(Plot $plot)
    {
        $irrigations = $plot->irrigations()->with(['labour', 'valves', 'creator'])
            ->when(request()->has('date'), function ($query) {
                $date = jalali_to_carbon(request()->query('date'));
                $query->whereDate('start_date', $date);
            }, function ($query) {
                $query->whereDate('start_date', today());
            })
            ->when(request()->has('status'), function ($query) {
                $query->filter(request()->query('status'));
            })
            ->latest()->get();

        return IrrigationResource::collection($irrigations);
    }

    /**
     * Get brief irrigation report for a plot.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Plot  $plot
     * @return \Illuminate\Http\JsonResponse
     */
    public function getIrrigationReportForPlot(Request $request, Plot $plot)
    {
        $date = $request->has('date') ? jalali_to_carbon($request->query('date')) : today();
        $irrigations = $plot->irrigations()->filter('finished')->with('valves')
            ->whereDate('start_date', $date)->get();

        $totalDuration = 0;
        $totalVolume = 0;
        $totalVolumePerHectare = 0;

        foreach ($irrigations as $irrigation) {
            $durationInSeconds = $irrigation->start_time->diffInSeconds($irrigation->end_time);
            $totalDuration += $durationInSeconds;

            foreach ($irrigation->valves as $valve) {
                $volume = ($valve->dripper_count * $valve->dripper_flow_rate) * ($durationInSeconds / 3600);
                $totalVolume += $volume;
                $totalVolumePerHectare += $volume / $valve->irrigation_area;
            }
        }

        return response()->json([
            'data' => [
                'date' => jdate($date)->format('Y/m/d'),
                'total_duration' => to_time_format($totalDuration),
                'total_volume' => $totalVolume,
                'total_volume_per_hectare' => $totalVolumePerHectare,
                'total_count' => $irrigations->count(),
            ]
        ]);
    }

    /**
     * Filter reports by date range.
     */
    public function filterReports(FilterIrrigationReportsRequest $request, Farm $farm)
    {
        $filters = [
            'labour_id' => $request->labour_id,
            'valves' => $request->valves,
            'from_date' => $request->from_date,
            'to_date' => $request->to_date,
        ];

        $plotIds = $request->plot_ids;

        // Build aggregated daily reports to match API contract expected by tests
        $reports = $this->irrigationReportService->getAggregatedReports($plotIds, $filters);

        return response()->json([
            'data' => $reports
        ]);
    }

    /**
     * Verify the specified irrigation.
     */
    public function verify(Request $request, Irrigation $irrigation)
    {
        $this->authorize('verify', $irrigation);

        if ($irrigation->is_verified_by_admin) {
            return response()->json([
                'message' => 'Irrigation already verified.',
            ], 422);
        }

        $irrigation->forceFill([
            'is_verified_by_admin' => true,
        ])->save();

        $irrigation->loadMissing(['labour', 'pump', 'valves', 'plots', 'creator']);

        return new IrrigationResource($irrigation);
    }

    /**
     * Get irrigation messages for a farm (finished irrigations of the day not verified by admin).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Farm  $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function getIrrigationMessages(Request $request, Farm $farm)
    {
        $today = today();

        // Determine verified filter value: if verified parameter is present, use it (0=false, 1=true), otherwise default to false
        $isVerified = $request->has('verified')
            ? (bool) $request->query('verified')
            : false;

        $irrigations = Irrigation::whereBelongsTo($farm)
            ->filter('finished')
            ->where('is_verified_by_admin', $isVerified)
            ->where(function ($query) use ($today) {
                $query->whereDate('start_date', '<=', $today)
                    ->where(function ($q) use ($today) {
                        $q->whereDate('end_date', '>=', $today)
                            ->orWhereNull('end_date')
                            ->whereDate('start_date', $today);
                    });
            })
            ->with(['plots', 'valves'])
            ->latest()
            ->get();

        $messages = $irrigations->map(function ($irrigation) use ($request) {
            $durationInSeconds = $irrigation->start_time->diffInSeconds($irrigation->end_time);
            $totalVolume = 0;
            $totalVolumePerHectare = 0;

            foreach ($irrigation->valves as $valve) {
                $volume = ($valve->dripper_count * $valve->dripper_flow_rate) * ($durationInSeconds / 3600);
                $totalVolume += $volume;
                if ($valve->irrigation_area > 0) {
                    $totalVolumePerHectare += $volume / $valve->irrigation_area;
                }
            }

            return [
                'irrigation_id' => $irrigation->id,
                'date' => jdate($irrigation->start_date)->format('Y/m/d'),
                'plots_names' => $irrigation->plots->pluck('name')->toArray(),
                'valves_names' => $irrigation->valves->pluck('name')->toArray(),
                'duration' => to_time_format($durationInSeconds),
                'irrigation_per_hectare' => round($totalVolumePerHectare, 2),
                'total_volume' => round($totalVolume, 2),
                'can' => [
                    'update' => $request->user()->can('update', $irrigation),
                    'verify' => $request->user()->can('verify', $irrigation),
                ]
            ];
        });

        return response()->json([
            'data' => $messages
        ]);
    }
}
