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
        // API clients may omit Accept: application/json. Never redirect an
        // unauthenticated API request to the web login route (which is not
        // registered in this API-only application); return the normal 401.
        $isApiRequest = str_starts_with($request->getPathInfo(), '/api/');

        return $isApiRequest || $request->expectsJson() ? null : route('login');
    }
}
