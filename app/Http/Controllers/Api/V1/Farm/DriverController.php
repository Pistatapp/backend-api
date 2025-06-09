<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverResource;
use App\Models\Driver;
use App\Models\Farm;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        $drivers = $farm->drivers()->simplePaginate(25);
        return DriverResource::collection($drivers);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Farm $farm)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'mobile' => 'required|ir_mobile|unique:drivers,mobile',
        ]);

        $driver = $farm->drivers()->create([
            'name' => $request->name,
            'mobile' => $request->mobile,
            'employee_code' => random_int(1000000, 9999999)
        ]);

        return new DriverResource($driver);
    }

    /**
     * Display the specified resource.
     */
    public function show(Driver $driver)
    {
        return new DriverResource($driver->load('tractor'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Driver $driver)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'mobile' => 'required|ir_mobile|unique:drivers,mobile,' . $driver->id,
        ]);

        $driver->update($request->only([
            'name',
            'mobile',
        ]));

        return new DriverResource($driver->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Driver $driver)
    {
        $driver->delete();

        return response()->noContent();
    }

    /**
     * Get available drivers for a farm (drivers without assigned tractors)
     */
    public function getAvailableDrivers(Farm $farm)
    {
        $drivers = $farm->drivers()->whereNull('tractor_id')->get();

        return response()->json([
            'data' => $drivers->map(function ($driver) {
                return [
                    'id' => $driver->id,
                    'name' => $driver->name,
                    'mobile' => $driver->mobile,
                    'employee_code' => $driver->employee_code
                ];
            }),
        ]);
    }
}
