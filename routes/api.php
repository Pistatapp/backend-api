<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\V1\Admin\CropController;
use App\Http\Controllers\Api\V1\Admin\CropTypeController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\User\Farm\FarmController;
use App\Http\Controllers\Api\V1\User\Farm\FieldController;
use App\Http\Controllers\Api\V1\User\Farm\RowController;
use App\Http\Controllers\Api\V1\User\Farm\TreeController;
use App\Http\Controllers\Api\V1\User\Farm\BlockController;
use App\Http\Controllers\Api\V1\User\Farm\PumpController;
use App\Http\Controllers\Api\V1\User\Farm\ValveController;
use App\Http\Controllers\Api\V1\User\Management\TeamController;
use App\Http\Controllers\Api\V1\User\Trucktor\DriverController;
use App\Http\Controllers\Api\V1\User\Trucktor\GpsReportController;
use App\Http\Controllers\Api\V1\User\Trucktor\TrucktorController;
use App\Http\Controllers\Api\V1\User\Management\LabourController;
use App\Http\Controllers\Api\V1\User\Farm\AttachmentController;
use App\Http\Controllers\Api\V1\User\Management\OprationController;
use App\Http\Controllers\Api\V1\User\Trucktor\TrucktorTaskController;
use App\Http\Controllers\Api\V1\User\Farm\IrrigationController;
use App\Http\Controllers\Api\V1\User\Trucktor\TrucktorReportController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\GpsDeviceController;
use App\Http\Controllers\Api\V1\Admin\PestController;
use App\Http\Controllers\Api\V1\Admin\PhonologyGuideFileController;
use App\Http\Controllers\Api\V1\User\Farm\ColdRequirementController;
use App\Http\Controllers\Api\V1\User\Farm\VolkOilSprayController;
use App\Http\Controllers\Api\V1\User\Trucktor\ActiveTrucktorController;
use App\Http\Controllers\Api\V1\User\Management\MaintenanceController;
use App\Http\Controllers\Api\V1\User\MaintenanceReportController;
use App\Http\Controllers\Api\V1\User\Farm\FarmReportsController;
use App\Http\Controllers\Api\V1\User\Farm\FrostbiteCalculationController;
use App\Http\Controllers\Api\V1\User\Farm\Phonology\DayDegreeCalculationController;
use App\Http\Controllers\Api\V1\Admin\LoadEstimationController;
use App\Http\Controllers\Api\V1\User\Farm\BlightCalculationController;
use App\Http\Controllers\Api\V1\User\Farm\FarmPlanController;
use App\Http\Controllers\Api\V1\User\Management\TreatmentController;
use App\Http\Controllers\Api\V1\User\Farm\WeatherForecastController;
use App\Http\Controllers\Api\V1\Admin\SliderController;
use App\Http\Controllers\Api\V1\User\DashboardController;
use Illuminate\Support\Facades\Broadcast;
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
});

Route::middleware(['auth:sanctum', 'last.activity', 'ensure.username'])->group(function () {

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::apiResource('gps_devices', GpsDeviceController::class)->except('show');
        Route::apiResource('users', UserController::class);

        Route::controller(CropController::class)->prefix('crops')->group(function () {
            Route::withoutMiddleware('admin')->group(function () {
                Route::get('/', 'index');
                Route::get('/{crop}', 'show');
            });
            Route::post('/', 'store');
            Route::put('/{crop}', 'update');
            Route::delete('/{crop}', 'destroy');
        });

        Route::apiResource('crops.crop_types', CropTypeController::class)->except('show')->shallow();

        Route::controller(PestController::class)->prefix('pests')->group(function () {
            Route::withoutMiddleware('admin')->group(function () {
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
                Route::withoutMiddleware('admin')->group(function () {
                    Route::get('/', 'index');
                });
                Route::post('/', 'store');
                Route::delete('/{id}', 'destroy');
            });

        Route::apiSingleton('crop_types.load_estimation', LoadEstimationController::class);

        Route::controller(SliderController::class)->prefix('sliders')->group(function () {
            Route::withoutMiddleware('admin')->group(function () {
                Route::get('/', 'index');
            });
            Route::post('/', 'store');
            Route::put('/{slider}', 'update');
            Route::delete('/{slider}', 'destroy');
        });
    });

    Route::withoutMiddleware('ensure.username')->group(function () {
        Route::patch('username', [ProfileController::class, 'setUsername']);
        Route::apiSingleton('profile', ProfileController::class);
    });

    Route::get('/farms/{farm}/set_working_environment', [FarmController::class, 'setWorkingEnvironment']);
    Route::apiResource('farms', FarmController::class);
    Route::apiResource('farms.farm-reports', FarmReportsController::class)->shallow();
    Route::apiResource('farms.fields', FieldController::class)->shallow();
    Route::apiResource('fields.rows', RowController::class)->except('update')->shallow();
    Route::post('rows/{row}/trees/batch_store', [TreeController::class, 'batchStore']);
    Route::apiResource('rows.trees', TreeController::class)->shallow();
    Route::apiResource('fields.blocks', BlockController::class)->shallow();
    Route::apiResource('farms.pumps', PumpController::class)->shallow();
    Route::get('/fields/{field}/valves', [FieldController::class, 'getValvesForField']);
    Route::apiResource('pumps.valves', ValveController::class)->shallow();
    Route::get('/farms/{farm}/trucktors/active', [ActiveTrucktorController::class, 'index']);
    Route::apiResource('farms.trucktors', TrucktorController::class)->shallow();
    Route::get('/trucktors/{trucktor}/devices', [TrucktorController::class, 'getAvailableDevices']);
    Route::post('/trucktors/{trucktor}/assign_device/{gps_device}', [TrucktorController::class, 'assignDevice']);
    Route::post('/trucktors/{trucktor}/unassign_device/{gps_device}', [TrucktorController::class, 'unassignDevice']);
    Route::get('/trucktors/{trucktor}/reports', [ActiveTrucktorController::class, 'reports']);
    Route::apiSingleton('trucktors.driver', DriverController::class)->creatable();
    Route::apiResource('trucktors.trucktor_reports', TrucktorReportController::class)->shallow();
    Route::post('/trucktor_reports/filter', [TrucktorReportController::class, 'filter']);
    Route::apiResource('farms.maintenances', MaintenanceController::class)->except('show')->shallow();
    Route::post(('maintenance_reports/filter'), [MaintenanceReportController::class, 'filter']);
    Route::apiResource('maintenance_reports', MaintenanceReportController::class)->except('show')->shallow();
    Route::apiResource('farms.teams', TeamController::class)->shallow();
    Route::apiResource('farms.labours', LabourController::class)->shallow();
    Route::apiResource('attachments', AttachmentController::class)->except('show', 'index');
    Route::apiResource('farms.operations', OprationController::class)->shallow();
    Route::apiResource('trucktors.trucktor_tasks', TrucktorTaskController::class)->shallow();
    Route::post('/farms/{farm}/irrigations/reports', [IrrigationController::class, 'filterReports']);
    Route::get('/fields/{field}/irrigations', [IrrigationController::class, 'getIrrigationsForField']);
    Route::get('/fields/{field}/irrigations/report', [IrrigationController::class, 'getIrrigationReportForField']);
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

    Broadcast::routes();
});

Route::post('/gps/reports', [GpsReportController::class, 'store']);
