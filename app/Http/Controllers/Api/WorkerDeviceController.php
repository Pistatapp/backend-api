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
            ->forFarm($farm->id)
            ->with(['labour', 'approver'])
            ->simplePaginate();

        return WorkerDeviceResource::collection($devices);
    }

    /**
     * Assign device to worker.
     */
    public function assign(AssignWorkerDeviceRequest $request, GpsDevice $device)
    {
        // Verify device belongs to user's farm
        $user = $request->user();
        if (!$device->farm_id || !$user->farms()->where('farms.id', $device->farm_id)->exists()) {
            abort(403, 'You do not have access to this device');
        }

        // Verify labour belongs to same farm
        $labour = \App\Models\Labour::findOrFail($request->validated()['labour_id']);
        if ($labour->farm_id !== $device->farm_id) {
            abort(403, 'Labour must belong to the same farm as the device');
        }

        // If worker already has a device, replace it
        $oldDevice = $labour->gpsDevice;
        if ($oldDevice && $oldDevice->id !== $device->id) {
            $device = $this->deviceManagementService->replaceWorkerDevice(
                $oldDevice->id,
                $device->id,
                $labour->id
            );
        } else {
            $device = $this->deviceManagementService->assignDeviceToWorker(
                $device->id,
                $labour->id
            );
        }

        return new WorkerDeviceResource($device->load(['labour', 'approver']));
    }

    /**
     * Unassign device from worker.
     */
    public function unassign(Request $request, GpsDevice $device)
    {
        // Verify device belongs to user's farm
        $user = $request->user();
        if (!$device->farm_id || !$user->farms()->where('farms.id', $device->farm_id)->exists()) {
            abort(403, 'You do not have access to this device');
        }

        $device->update(['labour_id' => null]);

        return new WorkerDeviceResource($device->load(['labour', 'approver']));
    }
}

