<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogRequests
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Exclude /api/gps/reports from logging
        if ($request->is('api/gps/reports') || $request->path() === 'api/gps/reports') {
            return $next($request);
        }

        $startTime = microtime(true);
        $ip = $request->ip();
        $date = now()->format('Y-m-d');

        // Sanitize IP address for filename (replace dots with underscores)
        $ipFilename = str_replace('.', '_', $ip);

        // Create log directory path: storage/logs/requests/{date}/
        $logDir = storage_path('logs/requests/' . $date);
        $logFile = $logDir . '/' . $ipFilename . '.log';

        // Ensure directory exists
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Prepare request log entry
        $requestLog = [
            'type' => 'REQUEST',
            'timestamp' => now()->toDateTimeString(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'ip' => $ip,
            'user_agent' => $request->userAgent(),
            'user_id' => $request->user()?->id,
        ];

        // Include request data (excluding sensitive fields)
        if ($request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('PATCH')) {
            $input = $request->except(['password', 'password_confirmation', 'token', 'api_token']);
            if (!empty($input)) {
                $requestLog['input'] = $input;
            }
        }

        // Write request log
        $this->writeLog($logFile, $requestLog);

        $response = $next($request);

        // Log response details
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $responseLog = [
            'type' => 'RESPONSE',
            'timestamp' => now()->toDateTimeString(),
            'method' => $request->method(),
            'path' => $request->path(),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => $duration,
        ];

        // Write response log
        $this->writeLog($logFile, $responseLog);

        return $response;
    }

    /**
     * Write log entry to file.
     *
     * @param string $logFile
     * @param array $data
     * @return void
     */
    private function writeLog(string $logFile, array $data): void
    {
        $logEntry = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $logEntry .= str_repeat('-', 80) . "\n\n";

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}

