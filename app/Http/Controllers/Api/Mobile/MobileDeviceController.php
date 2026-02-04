<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\MobileConnectRequest;
use App\Http\Resources\MobileDeviceStatusResource;
use App\Models\DeviceConnectionRequest;
use App\Models\GpsDevice;
use Illuminate\Http\Request;

class MobileDeviceController extends Controller
{
    /**
     * Create a connection request from mobile app.
     */
    public function connect(MobileConnectRequest $request)
    {
        $fingerprint = $request->validated()['device_fingerprint'];
        $mobileNumber = $request->validated()['mobile_number'];

        // Check if device already exists and is approved
        $existingDevice = GpsDevice::where('device_fingerprint', $fingerprint)
            ->whereNotNull('approved_at')
            ->first();

        if ($existingDevice) {
            return new MobileDeviceStatusResource([
                'status' => 'connected',
                'message' => 'Device is already connected and approved',
                'device_id' => $existingDevice->id,
            ]);
        }

        // Check if there's a pending request
        $pendingRequest = DeviceConnectionRequest::where('device_fingerprint', $fingerprint)
            ->where('status', 'pending')
            ->first();

        if ($pendingRequest) {
            return new MobileDeviceStatusResource([
                'status' => 'pending',
                'message' => 'Connection request is pending approval',
                'request_id' => $pendingRequest->id,
            ]);
        }

        // Create new connection request
        $connectionRequest = DeviceConnectionRequest::create([
            'mobile_number' => $mobileNumber,
            'device_fingerprint' => $fingerprint,
            'device_info' => $request->validated()['device_info'] ?? null,
            'status' => 'pending',
        ]);

        return new MobileDeviceStatusResource([
            'status' => 'requested',
            'message' => 'Connection request submitted successfully',
            'request_id' => $connectionRequest->id,
        ]);
    }

    /**
     * Update the status of a connection request.
     *
     * @param Request $request
     * @return MobileDeviceStatusResource
     */
    public function requestStatus(Request $request)
    {
        $fingerprint = $request->input('device_fingerprint');
        $request = DeviceConnectionRequest::where('device_fingerprint', $fingerprint)->first();

        return response()->json([
            'status' => $request->status ?? 'not_found',
            'request_id' => $request->id ?? null,
        ]);
    }
}
