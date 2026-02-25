<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\GpsDeviceResource;
use App\Models\GpsDevice;
use Illuminate\Http\Request;

class GpsDeviceController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(GpsDevice::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = GpsDevice::with('user');

        if (!$request->user()->hasRole('root')) {
            $query->where('user_id', $request->user()->id);
        }

        $devices = $query->paginate();
        return GpsDeviceResource::collection($devices);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'name' => 'required|string|max:255',
            'imei' => 'required|string|max:255|unique:gps_devices,imei',
            'sim_number' => 'required|string|max:255|unique:gps_devices,sim_number',
        ]);

        $device = GpsDevice::create([
            'user_id' => $request->user_id,
            'name' => $request->name,
            'imei' => $request->imei,
            'sim_number' => $request->sim_number,
        ]);

        return new GpsDeviceResource($device);
    }

    /**
     * Display the specified resource.
     */
    public function show(GpsDevice $gpsDevice)
    {
        $gpsDevice->load('user');
        return new GpsDeviceResource($gpsDevice);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, GpsDevice $gpsDevice)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'name' => 'required|string|max:255',
            'imei' => 'required|string|max:255|unique:gps_devices,imei,' . $gpsDevice->id,
            'sim_number' => 'required|string|max:255|unique:gps_devices,sim_number,' . $gpsDevice->id,
        ]);

        $gpsDevice->update([
            'user_id' => $request->user_id,
            'name' => $request->name,
            'imei' => $request->imei,
            'sim_number' => $request->sim_number,
        ]);

        return new GpsDeviceResource($gpsDevice->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(GpsDevice $gpsDevice)
    {
        $gpsDevice->delete();

        return response()->noContent();
    }
}
