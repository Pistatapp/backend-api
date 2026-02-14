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
            ]);

            return $device;
        });
    }

    /**
     * Assign device to worker (no-op: labour_id column removed from gps_devices).
     *
     * @param  int  $deviceId
     * @param  int  $labourId
     * @return GpsDevice
     */
    public function assignDeviceToWorker(int $deviceId, int $labourId): GpsDevice
    {
        return GpsDevice::findOrFail($deviceId)->fresh();
    }

    /**
     * Replace worker device (no-op: labour_id column removed from gps_devices).
     *
     * @param  int  $oldDeviceId
     * @param  int  $newDeviceId
     * @param  int  $labourId
     * @return GpsDevice
     */
    public function replaceWorkerDevice(int $oldDeviceId, int $newDeviceId, int $labourId): GpsDevice
    {
        return GpsDevice::findOrFail($newDeviceId)->fresh();
    }

    /**
     * Deactivate a device (no-op: is_active column removed from gps_devices).
     *
     * @param  int  $deviceId
     * @return GpsDevice
     */
    public function deactivateDevice(int $deviceId): GpsDevice
    {
        return GpsDevice::findOrFail($deviceId)->fresh();
    }
}

