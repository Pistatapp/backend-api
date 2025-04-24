<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverResource;
use App\Models\Tractor;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    /**
     * Get driver of the tractor.
     */
    public function show(Tractor $tractor)
    {
        $driver = $tractor->driver;
        return $driver ? new DriverResource($driver) : [];
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Tractor $tractor)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'mobile' => 'required|ir_mobile|unique:drivers,mobile',
        ]);

        $driver = $tractor->driver()->create([
            'name' => $request->name,
            'mobile' => $request->mobile,
            'employee_code' => random_int(1000000, 9999999)
        ]);

        return new DriverResource($driver);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Tractor $tractor)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'mobile' => 'required|ir_mobile|unique:drivers,mobile,' . $tractor->driver->id,
        ]);

        $driver = $tractor->driver;

        $driver->update($request->only([
            'name',
            'mobile',
        ]));

        return new DriverResource($driver->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Tractor $tractor)
    {
        $tractor->driver()->delete();

        return response()->noContent();
    }
}
