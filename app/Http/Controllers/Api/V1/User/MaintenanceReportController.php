<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\MaintenanceReportResource;
use App\Models\MaintenanceReport;
use Illuminate\Support\Str;
use Illuminate\Http\Request;

class MaintenanceReportController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(MaintenanceReport::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $maintenanceReports = get_active_farm()->maintenanceReports()->latest()->simplePaginate();
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
            'maintained_by' => 'required|integer|exists:labors,id',
            'date' => 'required|shamsi_date',
            'description' => 'required|string|max:500',
        ]);

        $maintable = $this->getMaintainableModel($request);

        $maintenanceReport = $maintable->maintenanceReports()->create([
            'maintenance_id' => $request->maintenance_id,
            'created_by' => auth()->id(),
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
            'maintained_by' => 'required|integer|exists:labors,id',
            'date' => 'required|shamsi_date',
            'description' => 'required|string|max:500',
        ]);

        $maintenanceReport->update([
            'maintenance_id' => $request->maintenance_id,
            'maintained_by' => $request->maintained_by,
            'date' => jalali_to_carbon($request->date),
            'description' => $request->description,
        ]);

        return new MaintenanceReportResource($maintenanceReport);
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
     * Get the maintainable model
     */
    private function getMaintainableModel(Request $request)
    {
        $model = 'App\Models\\' . Str::ucfirst($request->maintainable_type);

        if (!class_exists($model)) {
            abort(404, 'Model not found');
        }

        $maintable = $model::findOrFail($request->maintainable_id);

        return $maintable;
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
            'maintained_by' => 'nullable|integer|exists:labors,id',
            'maintenance_id' => 'nullable|integer|exists:maintenances,id',
        ]);

        $maintainable = $this->getMaintainableModel($request);

        $maintenanceReports = get_active_farm()->maintenanceReports()
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
            })->latest()->simplePaginate();

        return MaintenanceReportResource::collection($maintenanceReports);
    }
}
