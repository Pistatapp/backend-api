<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
        $attendanceTracking = $user->attendanceTracking;

        abort_if(
            ! $attendanceTracking || ! $attendanceTracking->enabled, 403,
            __('Attendance tracking not enabled for this user.')
        );

        return $next($request);
    }
}
