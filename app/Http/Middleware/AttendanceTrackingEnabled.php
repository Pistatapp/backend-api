<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\AttendanceTracking;

class AttendanceTrackingEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $workingEnvironment = $user->workingEnvironment();

        if (!$workingEnvironment) {
            return response()->json([
                'message' => __('You do not have a working environment.'),
            ], 403);
        }

        $attendanceTracking = AttendanceTracking::where('farm_id', $workingEnvironment->id)
            ->where('user_id', $user->id)
            ->first();

        if (!$attendanceTracking || !$attendanceTracking->enabled) {
            return response()->json([
                'message' => __('Attendance tracking not enabled for this user.'),
            ], 403);
        }

        return $next($request);
    }
}
