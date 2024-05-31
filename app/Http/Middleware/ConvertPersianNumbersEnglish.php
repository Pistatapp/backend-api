<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConvertPersianNumbersEnglish
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->merge(array_map(function ($value) {
            return is_string($value) ? $this->convertPersianNumbersToEnglish($value) : $value;
        }, $request->all()));

        return $next($request);
    }

    /**
     * Convert Persian numbers to English
     *
     * @param string $string
     * @return string
     */
    private function convertPersianNumbersToEnglish(string $string): string
    {
        $persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $englishNumbers = range(0, 9);

        return str_replace($persianNumbers, $englishNumbers, $string);
    }
}
