<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\Farm\FarmController;
use App\Http\Controllers\Api\V1\Farm\FieldController;
use App\Http\Controllers\Api\V1\Farm\RowController;
use App\Http\Controllers\Api\V1\Farm\TreeController;
use App\Http\Controllers\Api\V1\Farm\BlockController;
use App\Http\Controllers\Api\V1\Farm\PumpController;
use App\Http\Controllers\Api\V1\Farm\ValveController;
use App\Http\Controllers\Api\V1\Admin\GpsDeviceController;
use App\Http\Controllers\Api\V1\Management\TeamController;
use App\Http\Controllers\Api\V1\Trucktor\DriverController;
use App\Http\Controllers\Api\V1\Trucktor\GpsReportController;
use App\Http\Controllers\Api\V1\Trucktor\TrucktorController;
use App\Http\Controllers\Api\V1\Management\LaborController;
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
        Route::post('/send', 'sendToken');
        Route::post('/verify', 'verifyToken');
    });
    Route::post('logout', 'logout')->middleware('auth:sanctum');
});


Route::middleware(['auth:sanctum', 'last.activity'])->group(function () {

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::apiResource('gps-devices', GpsDeviceController::class)->except('show');
    });

    Route::apiSingleton('profile', ProfileController::class);

    Route::apiResource('farms', FarmController::class);
    Route::apiResource('farms.fields', FieldController::class)->shallow();
    Route::apiResource('fields.rows', RowController::class)->only('index', 'store', 'destroy')->shallow();
    Route::post('rows/{row}/trees/batch-store', [TreeController::class, 'batchStore']);
    Route::apiResource('rows.trees', TreeController::class)->shallow();
    Route::apiResource('fields.blocks', BlockController::class)->shallow();
    Route::apiResource('farms.pumps', PumpController::class)->shallow();
    Route::apiResource('pumps.valves', ValveController::class)->shallow();
    Route::apiResource('farms.trucktors', TrucktorController::class)->shallow();

    Route::get('/trucktors/{trucktor}/get-devices', [TrucktorController::class, 'getAvailableDevices']);
    Route::post('/trucktors/{trucktor}/assign-device/{gps_device}', [TrucktorController::class, 'assignDevice']);
    Route::post('/trucktors/{trucktor}/unassign-device/{gps_device}', [TrucktorController::class, 'unassignDevice']);
    Route::apiSingleton('trucktors.driver', DriverController::class)->creatable();
    Route::apiResource('farms.teams', TeamController::class)->shallow();
    Route::apiResource('teams.labors', LaborController::class)->shallow();
});

Route::post('/gps/reports', [GpsReportController::class, 'store']);