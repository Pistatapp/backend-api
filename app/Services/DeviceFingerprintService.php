<?php

namespace App\Services;

use App\Models\GpsDevice;

class DeviceFingerprintService
{
    /**
     * Validate if a device fingerprint is approved and active.
     *
     * @param  string  $fingerprint
     * @return bool
     */
    public function validateFingerprint(string $fingerprint): bool
    {
        $device = $this->getDeviceByFingerprint($fingerprint);

        if (!$device) {
            return false;
        }

        return $device !== null;
    }

    /**
     * Get device by fingerprint.
     *
     * @param  string  $fingerprint
     * @return GpsDevice|null
     */
    public function getDeviceByFingerprint(string $fingerprint): ?GpsDevice
    {
        return GpsDevice::where('device_fingerprint', $fingerprint)->first();
    }

    /**
     * Check if device is approved.
     *
     * @param  string  $fingerprint
     * @return bool
     */
    public function isDeviceApproved(string $fingerprint): bool
    {
        $device = $this->getDeviceByFingerprint($fingerprint);

        return $device !== null;
    }
}

