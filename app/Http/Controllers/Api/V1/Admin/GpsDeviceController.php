<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGpsDeviceRequest;
use App\Http\Requests\UpdateGpsDeviceRequest;
use App\Http\Resources\GpsDeviceResource;
use App\Models\GpsDevice;
use Illuminate\Http\Request;

class GpsDeviceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $devices = GpsDevice::with('user:id,username,mobile')->simplePaginate();
        return GpsDeviceResource::collection($devices);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreGpsDeviceRequest $request)
    {
        $device = GpsDevice::create($request->validated());

        return new GpsDeviceResource($device);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateGpsDeviceRequest $request, GpsDevice $gpsDevice)
    {
        $gpsDevice->update($request->validated());

        return new GpsDeviceResource($gpsDevice);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(GpsDevice $gpsDevice)
    {
        $this->authorize('delete', $gpsDevice);

        $gpsDevice->delete();

        return response()->noContent();
    }
}
