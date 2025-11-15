<?php

namespace App\Http\Controllers\Api\Worker;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\WorkerGpsData;
use App\Services\WorkerBoundaryDetectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Events\WorkerStatusChanged;
use Carbon\Carbon;

class WorkerGpsReportController extends Controller
{
    public function __construct(
        private WorkerBoundaryDetectionService $boundaryDetectionService,
    ) {}

    /**
     * Store GPS data from worker mobile app
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(Request $request)
    {
        try {
            // Get authenticated user (worker)
            $user = Auth::user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Find employee associated with this user
            $employee = Employee::where('user_id', $user->id)->first();
            
            if (!$employee) {
                return response()->json(['error' => 'Employee not found'], 404);
            }

            // Parse GPS data from request
            $gpsData = $this->parseGpsData($request);
            
            if (!$gpsData) {
                return response()->json(['error' => 'Invalid GPS data'], 400);
            }

            // Save GPS data
            $this->saveGpsData($gpsData, $employee->id);

            // Check boundary and update attendance (handle errors gracefully)
            try {
                $this->boundaryDetectionService->processGpsPoint(
                    $employee,
                    $gpsData['coordinate'],
                    Carbon::createFromTimestampMs($gpsData['time'])
                );
            } catch (\Exception $e) {
                // Log boundary detection errors but don't fail the request
                Log::warning('Boundary detection failed', [
                    'employee_id' => $employee->id,
                    'error' => $e->getMessage()
                ]);
            }

            // Broadcast worker status
            try {
                event(new WorkerStatusChanged($employee, $gpsData));
            } catch (\Exception $e) {
                // Log event errors but don't fail the request
                Log::warning('Failed to broadcast worker status', [
                    'employee_id' => $employee->id,
                    'error' => $e->getMessage()
                ]);
            }

            return response()->json(['success' => true], 200);
        } catch (\Exception $e) {
            Log::error('Worker GPS Report Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Parse GPS data from request
     *
     * @param Request $request
     * @return array|null
     */
    private function parseGpsData(Request $request): ?array
    {
        // Expected format from mobile app:
        // {
        //   "latitude": 34.052235,
        //   "longitude": -118.243683,
        //   "altitude": 92.4,
        //   "speed": 0.0,
        //   "bearing": 0.0,
        //   "accuracy": 5.1,
        //   "provider": "gps",
        //   "time": 1731632212000
        // }

        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');
        $altitude = $request->input('altitude', 0);
        $speed = $request->input('speed', 0);
        $bearing = $request->input('bearing', 0);
        $accuracy = $request->input('accuracy');
        $provider = $request->input('provider', 'gps');
        $time = $request->input('time');

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
            'bearing' => (float) $bearing,
            'accuracy' => $accuracy ? (float) $accuracy : null,
            'provider' => $provider,
            'time' => (int) $time,
        ];
    }

    /**
     * Save GPS data to database
     *
     * @param array $gpsData
     * @param int $employeeId
     * @return void
     */
    private function saveGpsData(array $gpsData, int $employeeId): void
    {
        WorkerGpsData::create([
            'employee_id' => $employeeId,
            'coordinate' => $gpsData['coordinate'],
            'speed' => $gpsData['speed'],
            'bearing' => $gpsData['bearing'],
            'accuracy' => $gpsData['accuracy'],
            'provider' => $gpsData['provider'],
            'date_time' => Carbon::createFromTimestampMs($gpsData['time']),
        ]);
    }
}
