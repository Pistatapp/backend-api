<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasWorkingEnvironment
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if($request->user()->hasRole('root')) {
            return $next($request);
        }

        if (! $request->user()->workingEnvironment()) {
            return response()->json([
                'message' => __('You do not have a working environment.'),
            ], 403);
        }

        return $next($request);
    }
}
