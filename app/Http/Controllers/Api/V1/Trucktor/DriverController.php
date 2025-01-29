<?php

namespace App\Http\Controllers\Api\V1\Trucktor;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverResource;
use App\Models\Trucktor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DriverController extends Controller
{
    /**
     * Get driver of the trucktor.
     */
    public function show(Trucktor $trucktor)
    {
        $driver = $trucktor->driver;
        return $driver ? new DriverResource($driver) : [];
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Trucktor $trucktor)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'mobile' => 'required|ir_mobile',
        ]);

        throw_if($trucktor->driver()->exists(), new \Exception('Driver already exists.'));

        $driver = $trucktor->driver()->create([
            'name' => $request->name,
            'mobile' => $request->mobile,
            'employee_code' => random_int(1000000, 9999999)
        ]);

        return new DriverResource($driver);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Trucktor $trucktor)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'mobile' => 'required|ir_mobile',
        ]);

        $driver = $trucktor->driver;

        throw_unless($driver, new \Exception('Driver not found.'));

        $driver->update($request->only([
            'name',
            'mobile',
        ]));

        return new DriverResource($driver->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Trucktor $trucktor)
    {
        throw_unless($trucktor->driver()->exists(), JsonResponse::HTTP_FORBIDDEN);

        $trucktor->driver()->delete();

        return response()->json([], JsonResponse::HTTP_GONE);
    }
}
