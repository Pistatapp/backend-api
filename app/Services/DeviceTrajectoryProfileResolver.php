<?php

namespace App\Services;

use App\Models\Tractor;

/** Resolves one centralized trajectory profile from the metadata PiStat has. */
class DeviceTrajectoryProfileResolver
{
    public function resolve(?Tractor $tractor = null): array
    {
        $device = $tractor?->relationLoaded('gpsDevice')
            ? $tractor->gpsDevice
            : $tractor?->gpsDevice;

        $metadata = strtolower(implode(' ', array_filter([
            $device?->device_type,
            $device?->name,
            $device?->imei,
        ])));

        $profileName = match (true) {
            str_contains($metadata, 'teltonika') => 'TELTONIKA',
            str_contains($metadata, 'hoosh') => 'HOOSHNICS_STANDARD',
            default => 'UNKNOWN',
        };

        return [
            'name' => $profileName,
            ...config('trajectory.profiles.'.$profileName, config('trajectory.profiles.UNKNOWN')),
        ];
    }
}
