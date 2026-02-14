<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\MobileGpsDataRequest;
use App\Models\LabourGpsData;
use App\Services\DeviceFingerprintService;
use App\Services\LabourBoundaryDetectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Events\LabourStatusChanged;
use Carbon\Carbon;

class MobileGpsController extends Controller
{
    public function __construct(
        private DeviceFingerprintService $fingerprintService,
        private LabourBoundaryDetectionService $boundaryDetectionService,
    ) {}

    /**
     * Store GPS data from mobile app (validated by device fingerprint).
     */
    public function store(MobileGpsDataRequest $request)
    {
        try {
            $fingerprint = $request->validated()['device_fingerprint'];

            // Validate device fingerprint
            if (!$this->fingerprintService->validateFingerprint($fingerprint)) {
                return response()->json(['error' => 'Device not approved or inactive'], 401);
            }

            // Get device
            $device = $this->fingerprintService->getDeviceByFingerprint($fingerprint);

            if (!$device) {
                return response()->json(['error' => 'Device not found'], 404);
            }

            $labourId = (int) $request->validated()['labour_id'];

            // Parse GPS data
            $gpsData = $this->parseGpsData($request);

            if (!$gpsData) {
                return response()->json(['error' => 'Invalid GPS data'], 400);
            }

            // Save GPS data
            $this->saveGpsData($gpsData, $labourId);

            // Get labour for boundary detection
            $labour = \App\Models\Labour::findOrFail($labourId);

            // Check boundary and update attendance (handle errors gracefully)
            try {
                $this->boundaryDetectionService->processGpsPoint(
                    $labour,
                    $gpsData['coordinate'],
                    Carbon::createFromTimestampMs($gpsData['time'])
                );
            } catch (\Exception $e) {
                // Log boundary detection errors but don't fail the request
                Log::warning('Boundary detection failed', [
                    'labour_id' => $labour->id,
                    'error' => $e->getMessage()
                ]);
            }

            // Broadcast labour status
            try {
                event(new LabourStatusChanged($labour, $gpsData));
            } catch (\Exception $e) {
                // Log event errors but don't fail the request
                Log::warning('Failed to broadcast labour status', [
                    'labour_id' => $labour->id,
                    'error' => $e->getMessage()
                ]);
            }

            return response()->json(['success' => true], 200);
        } catch (\Exception $e) {
            Log::error('Mobile GPS Report Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Parse GPS data from request.
     */
    private function parseGpsData(Request $request): ?array
    {
        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');
        $altitude = $request->input('altitude', 0);
        $speed = $request->input('speed', 0);
        $time = $request->input('time');
        $status = $request->input('status', 1); // 0 = stop, 1 = movement

        if (!$latitude || !$longitude || !$time) {
            return null;
        }

        return [
            'coordinate' => [
                'lat' => (float) $latitude,
                'lng' => (float) $longitude,
                'altitude' => (float) $altitude,
            ],
            'speed' => (float) $speed,
            'time' => (int) $time,
            'status' => (int) $status,
        ];
    }

    /**
     * Save GPS data to database.
     */
    private function saveGpsData(array $gpsData, int $labourId): void
    {
        LabourGpsData::create([
            'labour_id' => $labourId,
            'coordinate' => $gpsData['coordinate'],
            'speed' => $gpsData['speed'],
            'bearing' => 0, // Not provided in mobile payload
            'accuracy' => null, // Not provided in mobile payload
            'provider' => 'mobile',
            'date_time' => Carbon::createFromTimestampMs($gpsData['time']),
        ]);
    }
}

