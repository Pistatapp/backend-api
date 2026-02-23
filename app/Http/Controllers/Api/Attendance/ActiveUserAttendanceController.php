<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Models\User;
use App\Services\ActiveUserAttendanceService;
use App\Services\AttendancePathService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ActiveUserAttendanceController extends Controller
{
    public function __construct(
        private ActiveUserAttendanceService $activeUserService,
        private AttendancePathService $pathService,
    ) {}

    /**
     * Get active users with attendance tracking for a farm
     */
    public function index(Farm $farm)
    {
        $activeUsers = $this->activeUserService->getActiveUsers($farm);
        return response()->json(['data' => $activeUsers]);
    }

    /**
     * Get user attendance path for a specific date
     */
    public function getPath(User $user, Request $request)
    {
        $request->validate([
            'date' => 'required|shamsi_date',
        ]);

        $date = jalali_to_carbon($request->date);

        $path = $this->pathService->getUserPath($user, $date);
        return response()->json(['data' => $path]);
    }

    /**
     * Get user attendance performance for a specific date.
     * Uses the user's current working environment (farm).
     */
    public function getPerformance(User $user, Request $request)
    {
        $request->validate([
            'date' => 'required|shamsi_date',
        ]);

        $date = jalali_to_carbon($request->date);
        $farm = $user->workingEnvironment();

        $performance = $this->activeUserService->getPerformance($user, $farm, $date);
        return response()->json(['data' => $performance]);
    }
}
