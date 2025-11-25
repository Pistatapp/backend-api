<?php

namespace App\Providers;

use App\Models\Attachment;
use App\Models\Crop;
use App\Models\CropType;
use App\Models\Driver;
use App\Models\Farm;
use App\Models\FarmPlan;
use App\Models\FarmReport;
use App\Models\Field;
use App\Models\GpsDevice;
use App\Models\Irrigation;
use App\Models\Labour;
use App\Models\Maintenance;
use App\Models\MaintenanceReport;
use App\Models\NutrientDiagnosisRequest;
use App\Models\Operation;
use App\Models\Pest;
use App\Models\Plot;
use App\Models\Pump;
use App\Models\Row;
use App\Models\Slider;
use App\Models\Team;
use App\Models\Tractor;
use App\Models\TractorReport;
use App\Models\TractorTask;
use App\Models\Treatment;
use App\Models\Tree;
use App\Models\User;
use App\Models\Valve;
use App\Policies\AttachmentPolicy;
use App\Policies\CropPolicy;
use App\Policies\CropTypePolicy;
use App\Policies\DriverPolicy;
use App\Policies\FarmPlanPolicy;
use App\Policies\FarmPolicy;
use App\Policies\FarmReportPolicy;
use App\Policies\FieldPolicy;
use App\Policies\GpsDevicePolicy;
use App\Policies\IrrigationPolicy;
use App\Policies\LabourPolicy;
use App\Policies\MaintenancePolicy;
use App\Policies\MaintenanceReportPolicy;
use App\Policies\NutrientDiagnosisRequestPolicy;
use App\Policies\OperationPolicy;
use App\Policies\PestPolicy;
use App\Policies\PlotPolicy;
use App\Policies\PumpPolicy;
use App\Policies\RowPolicy;
use App\Policies\SliderPolicy;
use App\Policies\TeamPolicy;
use App\Policies\TractorPolicy;
use App\Policies\TractorReportPolicy;
use App\Policies\TractorTaskPolicy;
use App\Policies\TreatmentPolicy;
use App\Policies\TreePolicy;
use App\Policies\UserPolicy;
use App\Policies\ValvePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Attachment::class => AttachmentPolicy::class,
        Crop::class => CropPolicy::class,
        CropType::class => CropTypePolicy::class,
        Driver::class => DriverPolicy::class,
        Farm::class => FarmPolicy::class,
        FarmPlan::class => FarmPlanPolicy::class,
        FarmReport::class => FarmReportPolicy::class,
        Field::class => FieldPolicy::class,
        GpsDevice::class => GpsDevicePolicy::class,
        Irrigation::class => IrrigationPolicy::class,
        Labour::class => LabourPolicy::class,
        Maintenance::class => MaintenancePolicy::class,
        MaintenanceReport::class => MaintenanceReportPolicy::class,
        NutrientDiagnosisRequest::class => NutrientDiagnosisRequestPolicy::class,
        Operation::class => OperationPolicy::class,
        Pest::class => PestPolicy::class,
        Plot::class => PlotPolicy::class,
        Pump::class => PumpPolicy::class,
        Row::class => RowPolicy::class,
        Slider::class => SliderPolicy::class,
        Team::class => TeamPolicy::class,
        Tractor::class => TractorPolicy::class,
        TractorReport::class => TractorReportPolicy::class,
        TractorTask::class => TractorTaskPolicy::class,
        Treatment::class => TreatmentPolicy::class,
        Tree::class => TreePolicy::class,
        User::class => UserPolicy::class,
        Valve::class => ValvePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}
