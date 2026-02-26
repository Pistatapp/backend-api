<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectTelescopeGuests
{
    /**
     * Redirect unauthenticated users to the Telescope login page.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return redirect()->route('telescope.login')
                ->with('url.intended', $request->fullUrl());
        }

        return $next($request);
    }
}
