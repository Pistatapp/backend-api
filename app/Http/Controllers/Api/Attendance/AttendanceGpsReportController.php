<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceGpsData;
use App\Services\AttendanceBoundaryDetectionService;
use App\Events\UserAttendanceStatusChanged;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AttendanceGpsReportController extends Controller
{
    public function __construct(
        private AttendanceBoundaryDetectionService $boundaryDetectionService,
    ) {
        $this->middleware('attendance_tracking_enabled');
    }

    /**
     * Store GPS data from user mobile app
     */
    public function __invoke(Request $request)
    {
        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'speed' => 'required|numeric|min:0',
            'time' => 'required|integer',
            'exit' => 'required|boolean',
        ]);

        $user = $request->user();

        $gpsData = $this->parseGpsData($request);

        $this->saveGpsData($gpsData, $user->id);

        $this->boundaryDetectionService->processGpsPoint($user, $gpsData);

        event(new UserAttendanceStatusChanged($user, $gpsData));

        return response()->json(['success' => true], 200);
    }

    private function parseGpsData(Request $request): ?array
    {
        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');
        $speed = $request->input('speed', 0);
        $time = $request->input('time');
        $exit = $request->input('exit', false);

        return [
            'coordinate' => [(float) $latitude, (float) $longitude],
            'speed' => (float) $speed,
            'time' => (int) $time,
            'exit' => $exit,
        ];
    }

    private function saveGpsData(array $gpsData, int $userId): void
    {
        AttendanceGpsData::create([
            'user_id' => $userId,
            'coordinate' => $gpsData['coordinate'],
            'speed' => $gpsData['speed'],
            'date_time' => Carbon::createFromTimestampMs($gpsData['time']),
        ]);
    }
}
