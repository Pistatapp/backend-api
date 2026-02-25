<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Http\Resources\MaintenanceResource;
use App\Models\Farm;
use App\Models\Maintenance;
use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Maintenance::class, 'maintenance');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        $maintenances = Maintenance::query()
            ->where('farm_id', $farm->id)
            ->latest()
            ->paginate();

        return MaintenanceResource::collection($maintenances);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Farm $farm)
    {
        $request->validate([
            'name' => 'required|string',
        ]);

        $maintenance = $farm->maintenances()->create([
            'name' => $request->name,
        ]);

        return new MaintenanceResource($maintenance);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Maintenance $maintenance)
    {
        $request->validate([
            'name' => 'required|string',
        ]);

        $maintenance->update([
            'name' => $request->name,
        ]);

        return new MaintenanceResource($maintenance->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Maintenance $maintenance)
    {
        $maintenance->delete();

        return response()->noContent();
    }
}
