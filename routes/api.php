<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\V1\CropController;
use App\Http\Controllers\Api\V1\CropTypeController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\Farm\FarmController;
use App\Http\Controllers\Api\V1\Farm\FieldController;
use App\Http\Controllers\Api\V1\Farm\RowController;
use App\Http\Controllers\Api\V1\Farm\TreeController;
use App\Http\Controllers\Api\V1\Farm\PlotController;
use App\Http\Controllers\Api\V1\Farm\PumpController;
use App\Http\Controllers\Api\V1\Farm\ValveController;
use App\Http\Controllers\Api\V1\Management\TeamController;
use App\Http\Controllers\Api\V1\Farm\DriverController;
use App\Http\Controllers\Api\V1\Tractor\GpsReportController;
use App\Http\Controllers\Api\V1\Tractor\TractorController;
use App\Http\Controllers\Api\V1\Management\LabourController;
use App\Http\Controllers\Api\V1\Farm\AttachmentController;
use App\Http\Controllers\Api\V1\Management\OprationController;
use App\Http\Controllers\Api\V1\Tractor\TractorTaskController;
use App\Http\Controllers\Api\V1\Farm\IrrigationController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\GpsDeviceController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceConnectionRequestController;
use App\Http\Controllers\Api\WorkerDeviceController;
use App\Http\Controllers\Api\Mobile\MobileDeviceController;
use App\Http\Controllers\Api\Mobile\MobileGpsController;
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
use App\Http\Controllers\Api\V1\Farm\CompositionalNutrientDiagnosisController;
use App\Http\Controllers\Api\V1\UserPreferenceController;
use App\Http\Controllers\Api\V1\Tractor\TractorReportController;
use App\Http\Controllers\Api\V1\WarningController;
use App\Http\Controllers\Api\V1\PaymentController;
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
        Route::post('send', 'sendToken')->middleware('throttle:120,1');
        Route::post('verify', 'verifyToken');
    });
    Route::post('logout', 'logout')->middleware('auth:sanctum');
    Route::post('refresh', 'refreshToken')->middleware('auth:sanctum');
    Route::get('permissions', 'permissions')->middleware('auth:sanctum');
});

Route::middleware(['auth:sanctum', 'ensure.username'])->group(function () {

    // Device Management Routes (Root only)
    Route::middleware('role:root')->group(function () {
        Route::apiResource('devices', DeviceController::class);
        Route::get('device-connection-requests', [DeviceConnectionRequestController::class, 'index']);
        Route::post('device-connection-requests/{deviceConnectionRequest}/approve', [DeviceConnectionRequestController::class, 'approve']);
        Route::post('device-connection-requests/{deviceConnectionRequest}/reject', [DeviceConnectionRequestController::class, 'reject']);
    });

    // Keep old route for backward compatibility (deprecated)
    Route::apiResource('gps_devices', GpsDeviceController::class);
    Route::apiResource('crops', CropController::class);
    Route::apiResource('crops.crop_types', CropTypeController::class)->shallow();

    Route::delete('/pests/{pest}/image', [PestController::class, 'deleteImage']);
    Route::apiResource('pests', PestController::class);
    Route::apiResource('sliders', SliderController::class);

    Route::apiSingleton('crop_types.load_estimation', LoadEstimationController::class);

    Route::apiResource('phonology_guide_files', PhonologyGuideFileController::class)->only(['index', 'store', 'destroy']);

    Route::withoutMiddleware('ensure.username')->group(function () {
        Route::patch('username', [ProfileController::class, 'setUsername']);
        Route::apiSingleton('profile', ProfileController::class);
    });

    Route::apiResource('users', UserController::class);

    Route::get('/farms/{farm}/set_working_environment', [FarmController::class, 'setWorkingEnvironment']);
    Route::apiResource('farms', FarmController::class);
    Route::post('/farms/{farm}/attach_user', [FarmController::class, 'attachUserToFarm']);
    Route::post('/farms/{farm}/detach_user', [FarmController::class, 'detachUserFromFarm']);

    Route::patch('farm_reports/{farmReport}/verify', [FarmReportsController::class, 'verify']);
    Route::post('farms/{farm}/farm_reports/filter', [FarmReportsController::class, 'filter']);
    Route::apiResource('farms.farm_reports', FarmReportsController::class)->shallow();

    Route::apiResource('farms.fields', FieldController::class)->shallow();
    Route::apiResource('fields.rows', RowController::class)->except('update')->shallow();
    Route::post('rows/{row}/trees/batch_store', [TreeController::class, 'batchStore']);
    Route::apiResource('rows.trees', TreeController::class)->shallow();
    Route::apiResource('fields.plots', PlotController::class)->shallow();
    Route::apiResource('plots.valves', ValveController::class)->shallow();
    Route::apiResource('farms.pumps', PumpController::class)->shallow();

    // Tractors gps device and driver assignment routes
    Route::get('/farms/{farm}/gps-devices/available', [TractorController::class, 'getAvailableDevices']);
    Route::get('/farms/{farm}/tractors/available', [TractorController::class, 'getAvailableTractors']);
    Route::get('/farms/{farm}/drivers/available', [DriverController::class, 'getAvailableDrivers']);
    Route::post('/tractors/{tractor}/assignments', [TractorController::class, 'assignments']);

    // Active Tractors Routes
    Route::get('/farms/{farm}/tractors/active', [ActiveTractorController::class, 'index'])->can('view', 'farm');
    Route::get('/farms/{farm}/tractors/working', [ActiveTractorController::class, 'getWorkingTractors'])->can('view', 'farm');
    Route::get('/tractors/{tractor}/path', [ActiveTractorController::class, 'getPath'])->can('view', 'tractor');
    Route::get('/tractors/{tractor}/performance', [ActiveTractorController::class, 'getPerformance'])->can('view', 'tractor');
    Route::get('/tractors/{tractor}/weekly-efficiency-chart', [ActiveTractorController::class, 'getWeeklyEfficiencyChart'])->can('view', 'tractor');

    Route::apiResource('farms.tractors', TractorController::class)->shallow();
    Route::apiResource('/tractors.tractor_reports', TractorReportController::class)->shallow();
    Route::post('/tractor_reports/filter', [TractorReportController::class, 'filter'])->name('tractor.reports.filter');
    Route::apiResource('tractors.tractor_tasks', TractorTaskController::class)->shallow();
    Route::patch('/tractor_tasks/{tractor_task}/data', [TractorTaskController::class, 'patchData']);
    Route::post('/tractor_tasks/filter', [TractorTaskController::class, 'filterTasks'])->name('tractor_tasks.filter');
    Route::post('/tractors/filter_reports', [TractorTaskController::class, 'filterReports'])->name('tractor_reports.filter');


    // Drivers Routes
    Route::apiResource('farms.drivers', DriverController::class)->shallow();

    // Maintenance Routes
    Route::apiResource('farms.maintenances', MaintenanceController::class)->except('show')->shallow();
    Route::post('maintenance_reports/filter', [MaintenanceReportController::class, 'filter']);
    Route::apiResource('maintenance_reports', MaintenanceReportController::class);

    // Teams and Labours Routes
    Route::apiResource('farms.teams', TeamController::class)->shallow();
    Route::apiResource('farms.labours', LabourController::class)->shallow();

    // Worker Device Management Routes (Farm Admin)
    Route::middleware('role:admin')->group(function () {
        Route::get('farms/{farm}/worker-devices', [WorkerDeviceController::class, 'index']);
        Route::put('worker-devices/{device}/assign', [WorkerDeviceController::class, 'assign']);
        Route::put('worker-devices/{device}/unassign', [WorkerDeviceController::class, 'unassign']);
    });

    // Labour GPS and Attendance Routes
    Route::post('/labours/gps-report', [\App\Http\Controllers\Api\Labour\LabourGpsReportController::class, '__invoke']);
    Route::get('/farms/{farm}/labours/active', [\App\Http\Controllers\Api\ActiveLabourController::class, 'index']);
    Route::get('/labours/{labour}/path', [\App\Http\Controllers\Api\ActiveLabourController::class, 'getPath']);
    Route::get('/labours/{labour}/current-status', [\App\Http\Controllers\Api\ActiveLabourController::class, 'getCurrentStatus']);

    // Work Shifts Routes
    Route::apiResource('farms.work-shifts', \App\Http\Controllers\Api\WorkShiftController::class)->shallow();

    // Labour Shift Schedules Routes
    Route::get('/farms/{farm}/shift-schedules', [\App\Http\Controllers\Api\LabourShiftScheduleController::class, 'index']);
    Route::apiResource('shift-schedules', \App\Http\Controllers\Api\LabourShiftScheduleController::class)->except(['index']);

    // Labour Daily Reports Routes
    Route::get('/labour-daily-reports', [\App\Http\Controllers\Api\Labour\LabourDailyReportController::class, 'index']);
    Route::get('/labour-daily-reports/{labourDailyReport}', [\App\Http\Controllers\Api\Labour\LabourDailyReportController::class, 'show']);
    Route::patch('/labour-daily-reports/{labourDailyReport}', [\App\Http\Controllers\Api\Labour\LabourDailyReportController::class, 'update']);
    Route::post('/labour-daily-reports/{labourDailyReport}/approve', [\App\Http\Controllers\Api\Labour\LabourDailyReportController::class, 'approve']);

    // Labour Payroll Routes
    Route::post('/labour-payrolls/generate', [\App\Http\Controllers\Api\Labour\LabourPayrollController::class, 'generate']);
    Route::get('/labour-payrolls', [\App\Http\Controllers\Api\Labour\LabourPayrollController::class, 'index']);
    Route::get('/labour-payrolls/{labourMonthlyPayroll}', [\App\Http\Controllers\Api\Labour\LabourPayrollController::class, 'show']);

    // Human Resources Map Routes
    Route::get('/farms/{farm}/hr/active-labours', [\App\Http\Controllers\Api\HumanResourcesMapController::class, 'getActiveLabours']);

    // Attachments Routes
    Route::apiResource('attachments', AttachmentController::class)->except('show', 'index');
    Route::apiResource('farms.operations', OprationController::class)->shallow();

    // Irrigation Routes
    Route::post('/pumps/{pump}/irrigation-reports', [PumpController::class, 'generateIrrigationReport']);
    Route::get('/plots/{plot}/irrigation-statistics', [PlotController::class, 'getIrrigationStatistics']);
    Route::get('/irrigations/{irrigation}/plots/{plot}', [IrrigationController::class, 'getIrrigationStatisticsForPlot']);
    Route::post('/farms/{farm}/irrigations/filter-reports', [IrrigationController::class, 'filterReports']);
    Route::get('/farms/{farm}/irrigation-messages', [IrrigationController::class, 'getIrrigationMessages']);
    Route::patch('/irrigations/{irrigation}/verify', [IrrigationController::class, 'verify']);
    Route::apiResource('farms.irrigations', IrrigationController::class)->shallow();

    Route::apiResource('farms.treatments', TreatmentController::class)->shallow();
    Route::apiResource('farms.farm_plans', FarmPlanController::class)->shallow();
    Route::post('farms/{farm}/farm_plans/filter', [FarmPlanController::class, 'filter']);
    Route::post('farms/{farm}/cold_requirement', ColdRequirementController::class);
    Route::apiResource('farms.volk_oil_sprays', VolkOilSprayController::class)->shallow();

    Route::prefix('farms/{farm}')->group(function () {
        Route::post('/phonology/day_degree/calculate', DayDegreeCalculationController::class);
        Route::post('/frostbite/estimate', [FrostbiteCalculationController::class, 'estimate']);
        Route::get('/frostbite/notification', [FrostbiteCalculationController::class, 'getNotification']);
        Route::post('/frostbite/notification', [FrostbiteCalculationController::class, 'sendNotification']);
        Route::post('/blight/calculate', BlightCalculationController::class);

        // Nutrient Diagnosis Routes
        Route::get('/nutrient_diagnosis', [CompositionalNutrientDiagnosisController::class, 'index']);
        Route::get('/nutrient_diagnosis/{request}', [CompositionalNutrientDiagnosisController::class, 'show']);
        Route::post('/nutrient_diagnosis', [CompositionalNutrientDiagnosisController::class, 'store']);
        Route::delete('/nutrient_diagnosis/{request}', [CompositionalNutrientDiagnosisController::class, 'destroy']);
        Route::post('/nutrient_diagnosis/{request}/response', [CompositionalNutrientDiagnosisController::class, 'sendResponse']);
        Route::get('/nutrient_diagnosis/export', [CompositionalNutrientDiagnosisController::class, 'export']);

        Route::post('/load_estimation', [LoadEstimationController::class, 'estimate']);
        Route::post('/weather_forecast', WeatherForecastController::class)->name('farms.weather_forecast');
        Route::get('/dashboard/widgets', [DashboardController::class, 'dashboardWidgets']);
        Route::get('/dashboard/active-labours', [DashboardController::class, 'getActiveLabours']);
    });

    Route::controller(NotificationController::class)->prefix('notifications')->group(function () {
        Route::get('/', 'index');
        Route::post('/{id}/mark_as_read', 'markAsRead');
        Route::post('/mark_all_as_read', 'markAllAsRead');
    });


    // User Preferences Routes
    Route::prefix('preferences')->group(function () {
        Route::get('/', [UserPreferenceController::class, 'index']);
        Route::put('/', [UserPreferenceController::class, 'update']);
        Route::delete('/', [UserPreferenceController::class, 'reset']);
    });

    Broadcast::routes();
});

Route::post('/gps/reports', GpsReportController::class)->name('gps.reports');

// Mobile app routes (no authentication required)
Route::prefix('mobile')->group(function () {
    Route::post('request-status', [MobileDeviceController::class, 'requestStatus']);
    Route::post('connect', [MobileDeviceController::class, 'connect']);
    Route::post('gps', [MobileGpsController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'ensure.username'])->prefix('v1')->group(function () {
    Route::apiResource('warnings', WarningController::class)->only(['index', 'store']);

    // Payment routes
    Route::post('/payments/request', [PaymentController::class, 'request'])->name('payment.request');
    Route::get('/payments/verify', [PaymentController::class, 'verify'])->name('payment.verify');
});
