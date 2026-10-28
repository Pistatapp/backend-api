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
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'name' => 'required|string|max:255',
            'imei' => 'required|string|max:255|unique:gps_devices,imei',
            'sim_number' => 'required|string|max:255|unique:gps_devices,sim_number',
        ]);

        $device = GpsDevice::create($validated);

        return new GpsDeviceResource($device->load('user'));
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
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'name' => 'required|string|max:255',
            'imei' => 'required|string|max:255|unique:gps_devices,imei,' . $gpsDevice->id,
            'sim_number' => 'required|string|max:255|unique:gps_devices,sim_number,' . $gpsDevice->id,
        ]);

        $gpsDevice->update($validated);

        return new GpsDeviceResource($gpsDevice->fresh()->load('user'));
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
