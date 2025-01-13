<?php

namespace App\Providers;

use App\Listeners\IrrigationEventSubscriber;
use App\Services\WeatherApi;
use App\Services\Zarinpal\Zarinpal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        $this->app->singleton('weather-api', fn($app) => $app->make(WeatherApi::class));

        $this->app->bind('zarinpal', fn($app) => $app->make(Zarinpal::class));

        Event::subscribe(IrrigationEventSubscriber::class);
    }
}
