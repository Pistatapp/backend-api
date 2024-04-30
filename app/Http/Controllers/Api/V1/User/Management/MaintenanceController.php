<?php

namespace App\Http\Controllers\Api\V1\User\Management;

use App\Http\Controllers\Controller;
use App\Http\Resources\MaintenanceResource;
use App\Models\Farm;
use App\Models\Maintenance;
use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        if (request()->has('without_pagination')) {
            return MaintenanceResource::collection($farm->maintenances);
        } else {
            return MaintenanceResource::collection($farm->maintenances()->paginate());
        }
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

        return new MaintenanceResource($maintenance);
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
