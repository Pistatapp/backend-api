<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveDeviceConnectionRequest;
use App\Http\Resources\DeviceConnectionRequestResource;
use App\Models\DeviceConnectionRequest;
use App\Services\DeviceManagementService;
use Illuminate\Http\Request;

class DeviceConnectionRequestController extends Controller
{
    public function __construct(
        private DeviceManagementService $deviceManagementService
    ) {
    }

    /**
     * Display a listing of pending connection requests.
     */
    public function index(Request $request)
    {
        $query = DeviceConnectionRequest::with(['farm', 'approver']);

        // Filter by status if provided
        if ($request->has('status')) {
            $query->where('status', $request->status);
        } else {
            // Default to pending requests
            $query->pending();
        }

        $requests = $query->simplePaginate();
        return DeviceConnectionRequestResource::collection($requests);
    }

    /**
     * Approve a connection request and create a device.
     */
    public function approve(ApproveDeviceConnectionRequest $request, DeviceConnectionRequest $deviceConnectionRequest)
    {
        $device = $this->deviceManagementService->approveConnectionRequest(
            $deviceConnectionRequest->id,
            $request->validated()['farm_id'],
            $request->user()->id
        );

        return new DeviceConnectionRequestResource($deviceConnectionRequest->fresh()->load(['farm', 'approver']));
    }

    /**
     * Reject a connection request.
     */
    public function reject(Request $request, DeviceConnectionRequest $deviceConnectionRequest)
    {
        $request->validate([
            'rejected_reason' => 'nullable|string|max:500',
        ]);

        $deviceConnectionRequest->update([
            'status' => 'rejected',
            'rejected_reason' => $request->rejected_reason,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return new DeviceConnectionRequestResource($deviceConnectionRequest->fresh()->load(['farm', 'approver']));
    }
}

