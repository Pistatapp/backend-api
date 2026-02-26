<?php

namespace App\Providers;

use App\Services\WeatherApi;
use App\Services\KalmanFilter;
use App\Services\ActiveTractorService;
use App\Services\GpsPathCorrectorService;
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
        $this->app->singleton(KalmanFilter::class);
        $this->app->singleton(ActiveTractorService::class);
        $this->app->singleton(GpsPathCorrectorService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->singleton('weather-api', fn($app) => $app->make(WeatherApi::class));

        Gate::before(function ($user, $ability) {
            return true;
        });
    }
}
