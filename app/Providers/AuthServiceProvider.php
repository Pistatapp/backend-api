<?php

namespace App\Providers;

use App\Models\Irrigation;
use App\Models\Labour;
use App\Models\FarmReport;
use App\Models\Tractor;
use App\Policies\FarmReportPolicy;
use App\Policies\IrrigationPolicy;
use App\Policies\LabourPolicy;
use App\Policies\TractorPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Irrigation::class => IrrigationPolicy::class,
        Labour::class => LabourPolicy::class,
        FarmReport::class => FarmReportPolicy::class,
        Tractor::class => TractorPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}
