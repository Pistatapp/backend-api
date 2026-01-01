<?php

namespace App\Http\Controllers\Api\Labour;

use App\Http\Controllers\Controller;
use App\Models\Labour;
use App\Models\LabourGpsData;
use App\Services\LabourBoundaryDetectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Events\LabourStatusChanged;
use Carbon\Carbon;

class LabourGpsReportController extends Controller
{
    public function __construct(
        private LabourBoundaryDetectionService $boundaryDetectionService,
    ) {}

    /**
     * Store GPS data from labour mobile app
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(Request $request)
    {
        try {
            // Get authenticated user (labour)
            $user = Auth::user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Find labour associated with this user
            $labour = Labour::where('user_id', $user->id)->first();
            
            if (!$labour) {
                return response()->json(['error' => 'Labour not found'], 404);
            }

            // Parse GPS data from request
            $gpsData = $this->parseGpsData($request);
            
            if (!$gpsData) {
                return response()->json(['error' => 'Invalid GPS data'], 400);
            }

            // Save GPS data
            $this->saveGpsData($gpsData, $labour->id);

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
            Log::error('Labour GPS Report Error: ' . $e->getMessage(), [
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
     * @param int $labourId
     * @return void
     */
    private function saveGpsData(array $gpsData, int $labourId): void
    {
        LabourGpsData::create([
            'labour_id' => $labourId,
            'coordinate' => $gpsData['coordinate'],
            'speed' => $gpsData['speed'],
            'bearing' => $gpsData['bearing'],
            'accuracy' => $gpsData['accuracy'],
            'provider' => $gpsData['provider'],
            'date_time' => Carbon::createFromTimestampMs($gpsData['time']),
        ]);
    }
}

