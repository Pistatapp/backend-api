<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MaintenanceReportResource;
use App\Models\MaintenanceReport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MaintenanceReportController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(MaintenanceReport::class);
        $this->middleware('ensure_user_has_working_environment');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $workingEnvironment = $request->user()->workingEnvironment();
        $maintenanceReports = $workingEnvironment->maintenanceReports()->latest()->simplePaginate();
        return MaintenanceReportResource::collection($maintenanceReports);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'maintenance_id' => 'required|integer|exists:maintenances,id',
            'maintainable_type' => 'required|string',
            'maintainable_id' => 'required|integer',
            'maintained_by' => 'required|integer|exists:labours,id',
            'date' => 'required|shamsi_date',
            'description' => 'required|string|max:500',
        ]);

        $maintable = getModel($request->maintainable_type, $request->maintainable_id);

        $maintenanceReport = $maintable->maintenanceReports()->create([
            'maintenance_id' => $request->maintenance_id,
            'created_by' => $request->user()->id,
            'maintained_by' => $request->maintained_by,
            'date' => jalali_to_carbon($request->date),
            'description' => $request->description,
        ]);

        return new MaintenanceReportResource($maintenanceReport);
    }

    /**
     * Display the specified resource.
     */
    public function show(MaintenanceReport $maintenanceReport)
    {
        return new MaintenanceReportResource($maintenanceReport);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, MaintenanceReport $maintenanceReport)
    {
        $request->validate([
            'maintenance_id' => 'required|integer|exists:maintenances,id',
            'maintained_by' => 'required|integer|exists:labours,id',
            'date' => 'required|shamsi_date',
            'description' => 'required|string|max:500',
        ]);

        $maintenanceReport->update([
            'maintenance_id' => $request->maintenance_id,
            'maintained_by' => $request->maintained_by,
            'date' => jalali_to_carbon($request->date),
            'description' => $request->description,
        ]);

        return new MaintenanceReportResource($maintenanceReport->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MaintenanceReport $maintenanceReport)
    {
        $maintenanceReport->delete();

        return response()->json([], JsonResponse::HTTP_GONE);
    }

    /**
     * Filter maintenance reports by date
     */
    public function filter(Request $request)
    {
        $request->validate([
            'from' => 'required|shamsi_date',
            'to' => 'required|shamsi_date',
            'maintainable_type' => 'required|string',
            'maintainable_id' => 'required|integer',
            'maintained_by' => 'nullable|integer|exists:labours,id',
            'maintenance_id' => 'nullable|integer|exists:maintenances,id',
        ]);

        $maintainable = getModel($request->maintainable_type, $request->maintainable_id);

        $workingEnvironment = $request->user()->workingEnvironment();

        $maintenanceReports = $workingEnvironment->maintenanceReports()
            ->whereBetween('date', [
                jalali_to_carbon($request->from),
                jalali_to_carbon($request->to),
            ])
            ->where('maintainable_type', get_class($maintainable))
            ->where('maintainable_id', $maintainable->id)
            ->when($request->maintained_by, function ($query) use ($request) {
                return $query->where('maintained_by', $request->maintained_by);
            })
            ->when($request->maintenance_id, function ($query) use ($request) {
                return $query->where('maintenance_id', $request->maintenance_id);
            })
            ->latest()
            ->get();

        $groupedReports = $maintenanceReports->groupBy(function ($report) {
            return Carbon::parse($report->date)->format('Y/m/d');
        });

        $result = $groupedReports->map(function ($reports, $date) {
            return [
                'date' => jdate($date)->format('Y/m/d'),
                'maintenance' => $reports->first()->maintenance->name,
                'maintained_by' => $reports->first()->maintainedBy->full_name,
                'description' => $reports->first()->description,
            ];
        })->values();

        return response()->json(['data' => $result]);
    }
}
