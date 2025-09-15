<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Http\Resources\TractorResource;
use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TractorController extends Controller
{

    public function __construct()
    {
        $this->authorizeResource(Tractor::class, 'tractor');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        $tractors = $farm->tractors()->with('driver', 'gpsDevice', 'farm')->simplePaginate(25);
        return TractorResource::collection($tractors);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Farm $farm)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'start_work_time' => 'required|date_format:H:i',
            'end_work_time' => 'required|date_format:H:i',
            'expected_daily_work_time' => 'required|integer:0,24',
            'expected_monthly_work_time' => 'required|integer:0,744',
            'expected_yearly_work_time' => 'required|integer:0,8760',
        ]);

        $tractor = $farm->tractors()->create($request->only([
            'name',
            'start_work_time',
            'end_work_time',
            'expected_daily_work_time',
            'expected_monthly_work_time',
            'expected_yearly_work_time',
        ]));

        return new TractorResource($tractor);
    }

    /**
     * Display the specified resource.
     */
    public function show(Tractor $tractor)
    {
        $tractor->load([
            'driver',
            'gpsDevice',
            'farm',
            'gpsMetricsCalculations' => function ($query) {
                $query->latest('date')->limit(7);
            },
        ]);

        return new TractorResource($tractor);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Tractor $tractor)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'start_work_time' => 'required|date_format:H:i',
            'end_work_time' => 'required|date_format:H:i',
            'expected_daily_work_time' => 'required|integer',
            'expected_monthly_work_time' => 'required|integer',
            'expected_yearly_work_time' => 'required|integer',
        ]);

        $this->authorize('update', $tractor);

        $tractor->update($request->only([
            'name',
            'start_work_time',
            'end_work_time',
            'expected_daily_work_time',
            'expected_monthly_work_time',
            'expected_yearly_work_time',
        ]));

        return new TractorResource($tractor->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Tractor $tractor)
    {
        $tractor->delete();

        // if user wants to delete tractor, disaccosiate driver and gps device from tractor
        $tractor->driver()->update(['tractor_id' => null]);
        $tractor->gpsDevice()->update(['tractor_id' => null]);

        return response()->noContent();
    }

    /**
     * Get devices of the user which are not assigned to any tractor.
     */
    public function getAvailableDevices(Request $request)
    {
        $gpsDevices = $request->user()->gpsDevices()->whereDoesntHave('tractor')->get();

        return response()->json([
            'data' => $gpsDevices->map(function ($device) {
                return [
                    'id' => $device->id,
                    'name' => $device->name,
                    'imei' => $device->imei,
                ];
            }),
        ]);
    }

    /**
     * Get the available tractors to assign a device to.
     */
    public function getAvailableTractors(Request $request, Farm $farm)
    {
        $tractors = Tractor::where('farm_id', $farm->id)
            ->whereDoesntHave('gpsDevice')
            ->with(['driver', 'gpsDevice', 'farm'])
            ->get();

        return TractorResource::collection($tractors);
    }

    /**
     * Assign a driver and GPS device to a tractor.
     * This will replace any existing assignments.
     *
     * @param Request $request
     * @param Tractor $tractor
     * @return \Illuminate\Http\JsonResponse
     */
    public function assignments(Request $request, Tractor $tractor)
    {
        $request->validate([
            'driver_id' => 'required|exists:drivers,id',
            'gps_device_id' => 'required|exists:gps_devices,id',
        ]);

        // Load the farm relationship to avoid lazy loading issues
        $tractor->load('farm');

        // Get the new driver and GPS device
        $driver = Driver::findOrFail($request->driver_id);
        $device = GpsDevice::findOrFail($request->gps_device_id);

        // Authorize the assignments
        $this->authorize('makeAssignments', [$tractor, $device, $driver]);

        // Remove existing assignments first
        if ($tractor->driver) {
            $tractor->driver->update(['tractor_id' => null]);
        }
        if ($tractor->gpsDevice) {
            $tractor->gpsDevice->update(['tractor_id' => null]);
        }

        // Make new assignments
        $driver->update(['tractor_id' => $tractor->id]);
        $device->update(['tractor_id' => $tractor->id]);

        return response()->noContent();
    }
}
