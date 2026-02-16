<?php

namespace App\Services;

use App\Models\GpsDevice;

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

