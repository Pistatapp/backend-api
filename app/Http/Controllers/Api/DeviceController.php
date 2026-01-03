<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeviceRequest;
use App\Http\Requests\UpdateDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\GpsDevice;
use App\Services\DeviceManagementService;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function __construct(
        private DeviceManagementService $deviceManagementService
    ) {
        $this->authorizeResource(GpsDevice::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = GpsDevice::with(['user', 'farm', 'tractor', 'labour', 'approver']);

        // Filter by type if provided
        if ($request->has('type')) {
            if ($request->type === 'tractor') {
                $query->tractorDevices();
            } elseif ($request->type === 'worker') {
                $query->workerDevices();
            }
        }

        // Only root can see all devices
        if (!$request->user()->hasRole('root')) {
            abort(403, 'Only root users can access device management');
        }

        $devices = $query->simplePaginate();
        return DeviceResource::collection($devices);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreDeviceRequest $request)
    {
        $device = $this->deviceManagementService->createPersonalGpsDevice(
            $request->validated(),
            $request->user()->id
        );

        return new DeviceResource($device->load(['user', 'farm', 'tractor', 'labour', 'approver']));
    }

    /**
     * Display the specified resource.
     */
    public function show(GpsDevice $device)
    {
        $device->load(['user', 'farm', 'tractor', 'labour', 'approver']);
        return new DeviceResource($device);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateDeviceRequest $request, GpsDevice $device)
    {
        $device->update($request->validated());

        return new DeviceResource($device->fresh()->load(['user', 'farm', 'tractor', 'labour', 'approver']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(GpsDevice $device)
    {
        $device->delete();

        return response()->noContent();
    }
}

