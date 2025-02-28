<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConvertShamsiToGregorian
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->has('date')) {
            $date = $request->input('date');
            $gregorianDate = \Morilog\Jalali\CalendarUtils::createCarbonFromFormat('Y/m/d', $date)->format('Y/m/d');
            $request->merge(['date' => $gregorianDate]);
        }

        return $next($request);
    }
}
