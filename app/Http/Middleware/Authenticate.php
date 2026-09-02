<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // This application exposes an API and has no registered web login
        // route. Let Laravel return its standard 401 for every guard failure
        // instead of attempting a redirect to a missing route.
        return null;
    }
}
