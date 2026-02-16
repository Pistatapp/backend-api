<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceGpsData;
use App\Services\AttendanceBoundaryDetectionService;
use App\Events\UserAttendanceStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AttendanceGpsReportController extends Controller
{
    public function __construct(
        private AttendanceBoundaryDetectionService $boundaryDetectionService,
    ) {}

    /**
     * Store GPS data from user mobile app
     */
    public function __invoke(Request $request)
    {
        try {
            $user = Auth::user();

            if (! $user->attendanceTracking || ! $user->attendanceTracking->enabled) {
                return response()->json(['error' => 'Attendance tracking not enabled'], 403);
            }

            $gpsData = $this->parseGpsData($request);

            if (! $gpsData) {
                return response()->json(['error' => 'Invalid GPS data'], 400);
            }

            $this->saveGpsData($gpsData, $user->id);

            try {
                $this->boundaryDetectionService->processGpsPoint(
                    $user,
                    $gpsData['coordinate'],
                    Carbon::createFromTimestampMs($gpsData['time'])
                );
            } catch (\Exception $e) {
                Log::warning('Boundary detection failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                event(new UserAttendanceStatusChanged($user, $gpsData));
            } catch (\Exception $e) {
                Log::warning('Failed to broadcast attendance status', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json(['success' => true], 200);
        } catch (\Exception $e) {
            Log::error('Attendance GPS Report Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    private function parseGpsData(Request $request): ?array
    {
        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');
        $altitude = $request->input('altitude', 0);
        $speed = $request->input('speed', 0);
        $bearing = $request->input('bearing', 0);
        $accuracy = $request->input('accuracy');
        $provider = $request->input('provider', 'gps');
        $time = $request->input('time');

        if (! $latitude || ! $longitude || ! $time) {
            return null;
        }

        return [
            'coordinate' => [
                'lat' => (float) $latitude,
                'lng' => (float) $longitude,
                'altitude' => (float) $altitude,
            ],
            'speed' => (float) $speed,
            'bearing' => (float) $bearing,
            'accuracy' => $accuracy ? (float) $accuracy : null,
            'provider' => $provider,
            'time' => (int) $time,
        ];
    }

    private function saveGpsData(array $gpsData, int $userId): void
    {
        AttendanceGpsData::create([
            'user_id' => $userId,
            'coordinate' => $gpsData['coordinate'],
            'speed' => $gpsData['speed'],
            'bearing' => $gpsData['bearing'],
            'accuracy' => $gpsData['accuracy'],
            'provider' => $gpsData['provider'],
            'date_time' => Carbon::createFromTimestampMs($gpsData['time']),
        ]);
    }
}
