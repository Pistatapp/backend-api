<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\V1\CropController;
use App\Http\Controllers\Api\V1\CropTypeController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\Farm\FarmController;
use App\Http\Controllers\Api\V1\Farm\FieldController;
use App\Http\Controllers\Api\V1\Farm\RowController;
use App\Http\Controllers\Api\V1\Farm\TreeController;
use App\Http\Controllers\Api\V1\Farm\BlockController;
use App\Http\Controllers\Api\V1\Farm\PumpController;
use App\Http\Controllers\Api\V1\Farm\ValveController;
use App\Http\Controllers\Api\V1\Management\TeamController;
use App\Http\Controllers\Api\V1\Tractor\DriverController;
use App\Http\Controllers\Api\V1\Tractor\GpsReportController;
use App\Http\Controllers\Api\V1\Tractor\TractorController;
use App\Http\Controllers\Api\V1\Management\LabourController;
use App\Http\Controllers\Api\V1\Farm\AttachmentController;
use App\Http\Controllers\Api\V1\Management\OprationController;
use App\Http\Controllers\Api\V1\Tractor\TractorTaskController;
use App\Http\Controllers\Api\V1\Farm\IrrigationController;
use App\Http\Controllers\Api\V1\Tractor\TractorReportController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\GpsDeviceController;
use App\Http\Controllers\Api\V1\PestController;
use App\Http\Controllers\Api\V1\PhonologyGuideFileController;
use App\Http\Controllers\Api\V1\Farm\ColdRequirementController;
use App\Http\Controllers\Api\V1\Farm\VolkOilSprayController;
use App\Http\Controllers\Api\V1\Tractor\ActiveTractorController;
use App\Http\Controllers\Api\V1\Management\MaintenanceController;
use App\Http\Controllers\Api\V1\MaintenanceReportController;
use App\Http\Controllers\Api\V1\Farm\FarmReportsController;
use App\Http\Controllers\Api\V1\Farm\FrostbiteCalculationController;
use App\Http\Controllers\Api\V1\Farm\DayDegreeCalculationController;
use App\Http\Controllers\Api\V1\LoadEstimationController;
use App\Http\Controllers\Api\V1\Farm\BlightCalculationController;
use App\Http\Controllers\Api\V1\Farm\FarmPlanController;
use App\Http\Controllers\Api\V1\Management\TreatmentController;
use App\Http\Controllers\Api\V1\Farm\WeatherForecastController;
use App\Http\Controllers\Api\V1\SliderController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\FeatureController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::controller(AuthController::class)->prefix('auth')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::post('send', 'sendToken');
        Route::post('verify', 'verifyToken');
    });
    Route::post('logout', 'logout')->middleware('auth:sanctum');
    Route::post('refresh', 'refreshToken')->middleware('auth:sanctum');
    Route::get('permissions', 'permissions')->middleware('auth:sanctum');
});

Route::middleware(['auth:sanctum', 'last.activity', 'ensure.username'])->group(function () {

    Route::middleware('role:root')->group(function () {

        Route::apiResource('gps_devices', GpsDeviceController::class)->except('show');

        Route::controller(CropController::class)->prefix('crops')->group(function () {
            Route::withoutMiddleware('role:root')->group(function () {
                Route::get('/', 'index');
                Route::get('/{crop}', 'show');
            });
            Route::post('/', 'store');
            Route::put('/{crop}', 'update');
            Route::delete('/{crop}', 'destroy');
        });

        Route::apiResource('crops.crop_types', CropTypeController::class)->except('show')->shallow();

        Route::controller(PestController::class)->prefix('pests')->group(function () {
            Route::withoutMiddleware('role:root')->group(function () {
                Route::get('/', 'index');
                Route::get('/{pest}', 'show');
            });
            Route::post('/', 'store');
            Route::put('/{pest}', 'update');
            Route::delete('/{pest}', 'destroy');
            Route::delete('{pest}/image', 'deleteImage');
        });

        Route::controller(PhonologyGuideFileController::class)
            ->prefix('phonology/guide_files/{model_type}/{model_id}')->group(function () {
                Route::withoutMiddleware('role:root')->group(function () {
                    Route::get('/', 'index');
                });
                Route::post('/', 'store');
                Route::delete('/{id}', 'destroy');
            });

        Route::apiSingleton('crop_types.load_estimation', LoadEstimationController::class);

        Route::controller(SliderController::class)->prefix('sliders')->group(function () {
            Route::withoutMiddleware('role:root')->group(function () {
                Route::get('/', 'index');
            });
            Route::post('/', 'store');
            Route::put('/{slider}', 'update');
            Route::delete('/{slider}', 'destroy');
        });

        Route::controller(PlanController::class)->prefix('plans')->group(function () {
            Route::withoutMiddleware('role:root')->middleware('can:upgrade-user-level')->group(function () {
                Route::get('/', 'index');
                Route::get('/{plan}', 'show');
            });
            Route::post('/', 'store');
            Route::put('/{plan}', 'update');
            Route::delete('/{plan}', 'destroy');
        });

        Route::apiResource('plans.features', FeatureController::class)->shallow();
    });

    Route::withoutMiddleware('ensure.username')->group(function () {
        Route::patch('username', [ProfileController::class, 'setUsername']);
        Route::apiSingleton('profile', ProfileController::class);
    });

    Route::apiResource('users', UserController::class);

    Route::get('/farms/{farm}/set_working_environment', [FarmController::class, 'setWorkingEnvironment']);
    Route::apiResource('farms', FarmController::class);
    Route::post('/farms/{farm}/attach-user', [FarmController::class, 'attachUserToFarm']);
    Route::post('/farms/{farm}/detach-user', [FarmController::class, 'detachUserFromFarm']);
    Route::apiResource('farms.farm-reports', FarmReportsController::class)->shallow();
    Route::apiResource('farms.fields', FieldController::class)->shallow();
    Route::apiResource('fields.rows', RowController::class)->except('update')->shallow();
    Route::post('rows/{row}/trees/batch_store', [TreeController::class, 'batchStore']);
    Route::apiResource('rows.trees', TreeController::class)->shallow();
    Route::apiResource('fields.blocks', BlockController::class)->shallow();
    Route::apiResource('farms.pumps', PumpController::class)->shallow();
    Route::get('/fields/{field}/valves', [FieldController::class, 'getValvesForField']);
    Route::apiResource('pumps.valves', ValveController::class)->shallow();
    Route::get('/farms/{farm}/tractors/active', [ActiveTractorController::class, 'index']);
    Route::apiResource('farms.tractors', TractorController::class)->shallow();
    Route::get('/tractors/{tractor}/devices', [TractorController::class, 'getAvailableDevices']);
    Route::post('/tractors/{tractor}/assign_device/{gps_device}', [TractorController::class, 'assignDevice']);
    Route::post('/tractors/{tractor}/unassign_device/{gps_device}', [TractorController::class, 'unassignDevice']);
    Route::get('/tractors/{tractor}/reports', [ActiveTractorController::class, 'reports']);
    Route::apiSingleton('tractors.driver', DriverController::class)->creatable();
    Route::apiResource('tractors.tractor_tasks', TractorTaskController::class)->shallow();
    Route::post('/tractors/filter_reports', [TractorTaskController::class, 'filterReports'])->name('tractor.reports.filter');
    Route::apiResource('farms.maintenances', MaintenanceController::class)->except('show')->shallow();
    Route::post(('maintenance_reports/filter'), [MaintenanceReportController::class, 'filter']);
    Route::apiResource('maintenance_reports', MaintenanceReportController::class)->except('show')->shallow();
    Route::apiResource('farms.teams', TeamController::class)->shallow();
    Route::apiResource('farms.labours', LabourController::class)->shallow();
    Route::apiResource('attachments', AttachmentController::class)->except('show', 'index');
    Route::apiResource('farms.operations', OprationController::class)->shallow();

    Route::post('/farms/{farm}/irrigations/reports', [IrrigationController::class, 'filterReports'])->name('irrigations.reports.filter');
    Route::get('/fields/{field}/irrigations', [IrrigationController::class, 'getIrrigationsForField'])->name('fields.irrigations');
    Route::get('/fields/{field}/irrigations/report', [IrrigationController::class, 'getIrrigationReportForField'])->name('fields.irrigations.report');
    Route::apiResource('farms.irrigations', IrrigationController::class)->shallow();

    Route::apiResource('farms.treatments', TreatmentController::class)->shallow();
    Route::apiResource('farms.farm_plans', FarmPlanController::class)->shallow();
    Route::post('farms/{farm}/cold_requirement', ColdRequirementController::class);
    Route::apiResource('farms.volk_oil_sprays', VolkOilSprayController::class)->shallow();

    Route::prefix('farms/{farm}')->group(function () {
        Route::post('/phonology/day_degree/calculate', DayDegreeCalculationController::class);
        Route::post('/frostbite/estimate', [FrostbiteCalculationController::class, 'estimate']);
        Route::get('/frostbite/notification', [FrostbiteCalculationController::class, 'getNotification']);
        Route::post('/frostbite/notification', [FrostbiteCalculationController::class, 'sendNotification']);
        Route::post('/blight/calculate', BlightCalculationController::class);
    });

    Route::post('/farms/{farm}/load_estimation', [LoadEstimationController::class, 'estimate']);

    Route::post('/farms/{farm}/weather_forecast', WeatherForecastController::class);

    Route::get('/farms/{farm}/dashboard/widgets', [DashboardController::class, 'dashboardWidgets']);

    Route::controller(NotificationController::class)->prefix('notifications')->group(function () {
        Route::get('/', 'index');
        Route::post('/{id}/mark_as_read', 'markAsRead');
        Route::post('/mark_all_as_read', 'markAllAsRead');
    });
});

Route::post('/gps/reports', GpsReportController::class)->name('gps.reports');
