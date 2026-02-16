<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\GpsDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileDeviceController extends Controller
{

    /**
     * Get connection status for a device (by device_fingerprint).
     *
     * @return JsonResponse
     */
    public function connectionStatus(Request $request): JsonResponse
    {
        $request->validate([
            'device_fingerprint' => 'required|string|max:255'
        ]);

        $fingerprint = $request->input('device_fingerprint');
        $gpsDevice = GpsDevice::where('device_fingerprint', $fingerprint)->first();

        $status = ($gpsDevice && ! empty($gpsDevice->imei)) ? 'connected' : 'not-connected';

        return response()->json([
            'status' => $status,
            'device_fingerprint' => $fingerprint,
        ]);
    }

    /**
     * Connect device from mobile app (register/update IMEI for user's GPS device).
     *
     *
     */
    public function connect(Request $request): JsonResponse
    {
        $request->validate([
            'sim_number' => 'required|ir_mobile:zero',
            'device_fingerprint' => 'required|string|max:255',
            'imei' => 'required|string|size:16|regex:/^[0-9]{16}$/',
        ]);

        $gpsDevice = GpsDevice::where('sim_number', $request->sim_number)->first();

        if (! $gpsDevice) {
            return response()->json([
                'status' => 'not-found',
                'message' => 'No device record found for this user',
            ], 404);
        }

        if (! empty($gpsDevice->imei)) {
            return response()->json([
                'status' => 'connected',
                'message' => 'Device is already connected and approved',
                'device_id' => $gpsDevice->id,
            ]);
        }

        $gpsDevice->update([
            'device_fingerprint' => $request->device_fingerprint,
            'imei' => $request->imei
        ]);

        return response()->json([
            'status' => 'connected',
            'message' => 'Device connected successfully',
            'device_id' => $gpsDevice->id,
        ]);
    }
}
