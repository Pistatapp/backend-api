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
            str_contains($metadata, 'teltonika') || str_contains($metadata, 'تلتونیکا') => 'TELTONIKA',
            str_contains($metadata, 'hoosh') || str_contains($metadata, 'هوشنیکس') => 'HOOSHNICS_STANDARD',
            default => 'UNKNOWN',
        };

        $defaults = [
            'noise_radius_meters' => 15.0,
            'max_plausible_speed_kmh' => 45.0,
            'gap_seconds' => 600,
        ];
        $configured = config('trajectory.profiles.'.$profileName);
        if (is_array($configured)) {
            $defaults = array_merge($defaults, $configured);
        }

        return [
            'name' => $profileName,
            ...$defaults,
        ];
    }
}
