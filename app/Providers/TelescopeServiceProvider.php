<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            // Exclude /api/gps/reports from monitoring
            if ($entry->type === 'request') {
                $content = $entry->content ?? [];

                // Get the URI from various possible keys
                $uri = null;
                if (isset($content['uri'])) {
                    $uri = $content['uri'];
                } elseif (isset($content['path'])) {
                    $uri = $content['path'];
                } elseif (isset($content['url'])) {
                    $uri = parse_url($content['url'], PHP_URL_PATH);
                }

                // Exclude /api/gps/reports route
                if ($uri && is_string($uri) && $uri === '/api/gps/reports') {
                    return false;
                }
            }

            // Always record in local environment (except excluded routes above)
            if ($isLocal) {
                return true;
            }

            // Record exceptions, failed requests, failed jobs, scheduled tasks, and tagged entries
            if ($entry->isReportableException() ||
                $entry->isFailedRequest() ||
                $entry->isFailedJob() ||
                $entry->isScheduledTask() ||
                $entry->hasMonitoredTag()) {
                return true;
            }

            // Record API requests - check if entry is a request type and path starts with /api/
            if ($entry->type === 'request') {
                $content = $entry->content ?? [];

                // The URI is typically stored in the content array
                // Try different possible keys where the URI might be stored
                $uri = null;

                if (isset($content['uri'])) {
                    $uri = $content['uri'];
                } elseif (isset($content['path'])) {
                    $uri = $content['path'];
                } elseif (isset($content['url'])) {
                    $uri = parse_url($content['url'], PHP_URL_PATH);
                }

                // Check if URI starts with /api/
                if ($uri && is_string($uri) && str_starts_with($uri, '/api/')) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters(['_token']);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', function ($user) {
            return $user->hasRole('root');
        });
    }
}
