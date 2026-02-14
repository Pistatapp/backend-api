<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignWorkerDeviceRequest;
use App\Http\Resources\WorkerDeviceResource;
use App\Models\Farm;
use App\Models\GpsDevice;
use App\Services\DeviceManagementService;
use Illuminate\Http\Request;

class WorkerDeviceController extends Controller
{
    public function __construct(
        private DeviceManagementService $deviceManagementService
    ) {
    }

    /**
     * List devices allocated to the orchard admin's farm.
     */
    public function index(Request $request, Farm $farm)
    {
        // Verify the user has access to this farm
        if (!$request->user()->farms()->where('farms.id', $farm->id)->exists()) {
            abort(403, 'You do not have access to this farm');
        }

        $devices = GpsDevice::workerDevices()
            ->whereHas('user', fn ($q) => $q->whereHas('farms', fn ($q2) => $q2->where('farms.id', $farm->id)))
            ->simplePaginate();

        return WorkerDeviceResource::collection($devices);
    }

    /**
     * Assign device to worker.
     * Note: Device–worker assignment (labour_id) has been removed from gps_devices.
     */
    public function assign(AssignWorkerDeviceRequest $request, GpsDevice $device)
    {
        abort(501, 'Device-to-worker assignment is no longer supported');
    }

    /**
     * Unassign device from worker.
     * Note: Device–worker assignment (labour_id) has been removed from gps_devices.
     */
    public function unassign(Request $request, GpsDevice $device)
    {
        abort(501, 'Device-to-worker unassignment is no longer supported');
    }
}

