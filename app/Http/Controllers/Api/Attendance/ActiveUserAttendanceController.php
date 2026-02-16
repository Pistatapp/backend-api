<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserAttendanceStatusResource;
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
        $date = $request->input('date', Carbon::today()->toDateString());
        $carbonDate = Carbon::parse($date);

        $path = $this->pathService->getUserPath($user, $carbonDate);
        return response()->json(['data' => $path]);
    }

    /**
     * Get current attendance status of a user
     */
    public function getCurrentStatus(User $user)
    {
        $latestPoint = $this->pathService->getLatestPoint($user);

        $user->setRelation('activeAttendanceSession',
            $user->attendanceSessions()
                ->whereDate('date', Carbon::today())
                ->where('status', 'in_progress')
                ->first()
        );

        $user->setAttribute('latest_gps', $latestPoint);

        return new UserAttendanceStatusResource($user);
    }
}
