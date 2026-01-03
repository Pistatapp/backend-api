<?php

namespace App\Services;

use App\Models\DeviceConnectionRequest;
use App\Models\GpsDevice;
use Illuminate\Support\Facades\DB;

class DeviceManagementService
{
    /**
     * Create a Personal GPS device (direct registration by Root).
     *
     * @param  array  $data
     * @param  int  $userId
     * @return GpsDevice
     */
    public function createPersonalGpsDevice(array $data, int $userId): GpsDevice
    {
        return GpsDevice::create([
            'user_id' => $userId,
            'device_type' => $data['device_type'], // 'personal_gps' or 'tractor_gps'
            'name' => $data['name'],
            'imei' => $data['imei'],
            'sim_number' => $data['sim_number'] ?? null,
            'tractor_id' => $data['tractor_id'] ?? null,
            'farm_id' => $data['farm_id'] ?? null,
            'is_active' => true,
            'approved_at' => now(),
            'approved_by' => $userId,
        ]);
    }

    /**
     * Approve a connection request and create a GpsDevice.
     *
     * @param  int  $requestId
     * @param  int  $farmId
     * @param  int  $userId
     * @return GpsDevice
     */
    public function approveConnectionRequest(int $requestId, int $farmId, int $userId): GpsDevice
    {
        return DB::transaction(function () use ($requestId, $farmId, $userId) {
            $request = DeviceConnectionRequest::findOrFail($requestId);

            // Update request status
            $request->update([
                'status' => 'approved',
                'farm_id' => $farmId,
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            // Create GpsDevice
            $device = GpsDevice::create([
                'user_id' => $userId,
                'device_type' => 'mobile_phone',
                'name' => 'Mobile Phone - ' . $request->mobile_number,
                'device_fingerprint' => $request->device_fingerprint,
                'mobile_number' => $request->mobile_number,
                'farm_id' => $farmId,
                'is_active' => true,
                'approved_at' => now(),
                'approved_by' => $userId,
            ]);

            return $device;
        });
    }

    /**
     * Assign device to worker (by Orchard Admin).
     *
     * @param  int  $deviceId
     * @param  int  $labourId
     * @return GpsDevice
     */
    public function assignDeviceToWorker(int $deviceId, int $labourId): GpsDevice
    {
        $device = GpsDevice::findOrFail($deviceId);

        // If device was previously assigned to another worker, unassign it
        if ($device->labour_id && $device->labour_id !== $labourId) {
            // Old device is automatically deactivated when new one is assigned
            // (handled in the controller/service that calls this)
        }

        $device->update([
            'labour_id' => $labourId,
        ]);

        return $device->fresh();
    }

    /**
     * Replace worker device (deactivate old, assign new).
     *
     * @param  int  $oldDeviceId
     * @param  int  $newDeviceId
     * @param  int  $labourId
     * @return GpsDevice
     */
    public function replaceWorkerDevice(int $oldDeviceId, int $newDeviceId, int $labourId): GpsDevice
    {
        return DB::transaction(function () use ($oldDeviceId, $newDeviceId, $labourId) {
            // Deactivate old device
            if ($oldDeviceId) {
                $oldDevice = GpsDevice::find($oldDeviceId);
                if ($oldDevice) {
                    $oldDevice->update(['is_active' => false, 'labour_id' => null]);
                }
            }

            // Assign new device
            return $this->assignDeviceToWorker($newDeviceId, $labourId);
        });
    }

    /**
     * Deactivate a device.
     *
     * @param  int  $deviceId
     * @return GpsDevice
     */
    public function deactivateDevice(int $deviceId): GpsDevice
    {
        $device = GpsDevice::findOrFail($deviceId);
        $device->update(['is_active' => false]);
        return $device->fresh();
    }
}

