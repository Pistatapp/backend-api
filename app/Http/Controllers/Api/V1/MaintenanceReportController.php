<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MaintenanceReportResource;
use App\Models\MaintenanceReport;
use Carbon\Carbon;
use Illuminate\Http\Request;

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
        $maintenanceReports = $workingEnvironment->maintenanceReports()->latest()->paginate();

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
            'repair_shop_entered_at' => 'nullable|date',
            'repair_shop_exited_at' => 'nullable|date',
            'next_maintenance_km' => 'nullable|numeric|min:0',
        ]);

        $maintainableType = getModelClass($request->maintainable_type);

        $maintainable = $maintainableType::findOrFail($request->maintainable_id);

        $maintenanceReport = $maintainable->maintenanceReports()->create([
            'maintenance_id' => $request->maintenance_id,
            'created_by' => $request->user()->id,
            'maintained_by' => $request->maintained_by,
            'date' => jalali_to_carbon($request->date),
            'description' => $request->description,
            'repair_shop_entered_at' => $request->repair_shop_entered_at
                ? Carbon::parse($request->repair_shop_entered_at)
                : null,
            'repair_shop_exited_at' => $request->repair_shop_exited_at
                ? Carbon::parse($request->repair_shop_exited_at)
                : null,
            'next_maintenance_km' => $request->next_maintenance_km,
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
            'repair_shop_entered_at' => 'nullable|date',
            'repair_shop_exited_at' => 'nullable|date',
            'next_maintenance_km' => 'nullable|numeric|min:0',
        ]);

        $data = [
            'maintenance_id' => $request->maintenance_id,
            'maintained_by' => $request->maintained_by,
            'date' => jalali_to_carbon($request->date),
            'description' => $request->description,
        ];

        if ($request->has('repair_shop_entered_at')) {
            $data['repair_shop_entered_at'] = $request->repair_shop_entered_at
                ? Carbon::parse($request->repair_shop_entered_at)
                : null;
        }

        if ($request->has('repair_shop_exited_at')) {
            $data['repair_shop_exited_at'] = $request->repair_shop_exited_at
                ? Carbon::parse($request->repair_shop_exited_at)
                : null;
        }

        if ($request->has('next_maintenance_km')) {
            $data['next_maintenance_km'] = $request->input('next_maintenance_km');
        }

        $maintenanceReport->update($data);

        return new MaintenanceReportResource($maintenanceReport->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MaintenanceReport $maintenanceReport)
    {
        $maintenanceReport->delete();

        return response()->noContent();
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

        $maintainableType = getModelClass($request->maintainable_type);

        $fromDate = jalali_to_carbon($request->from)->startOfDay();
        $toDate = jalali_to_carbon($request->to)->endOfDay();

        $workingEnvironment = $request->user()->workingEnvironment();

        $reports = $workingEnvironment->maintenanceReports()
            ->whereDate('date', '>=', $fromDate)
            ->whereDate('date', '<=', $toDate)
            ->where('maintainable_type', $maintainableType)
            ->where('maintainable_id', $request->maintainable_id)
            ->when($request->maintained_by, function ($query) use ($request) {
                return $query->where('maintained_by', $request->maintained_by);
            })
            ->when($request->maintenance_id, function ($query) use ($request) {
                return $query->where('maintenance_id', $request->maintenance_id);
            })
            ->latest()
            ->get();

        return MaintenanceReportResource::collection($reports);
    }
}
