<?php

namespace App\Providers;

use App\Services\WeatherApi;
use App\Services\KalmanFilter;
use App\Services\TractorReportService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
        $this->app->singleton(KalmanFilter::class);
        $this->app->singleton(TractorReportService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->singleton('weather-api', fn($app) => $app->make(WeatherApi::class));

        Gate::before(function ($user, $ability) {
            return $user->hasRole('root') ? true : null;
        });
    }
}
