<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class LastActivity
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (Auth::check()) {
            $user = $request->user();
            $wasOffline = !$user->is_online;

            $user->forceFill([
                'last_activity_at' => now(),
                'is_online' => true,
                'last_seen_at' => now(),
            ])->save();

            // Broadcast online status change if user just came online
            if ($wasOffline) {
                event(new \App\Events\UserOnlineStatusChanged($user, true));
            }
        }

        return $response;
    }
}
