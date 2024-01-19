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
    Route::apiSingleton('profile', ProfileController::class);

    Route::apiResource('farms', FarmController::class);
    Route::apiResource('farms.fields', FieldController::class)->shallow();
    Route::apiResource('fields.rows', RowController::class)->only('index', 'store', 'destroy')->shallow();
    Route::post('rows/{row}/trees/batch-store', [TreeController::class, 'batchStore'])->name('rows.trees.batch-store');
    Route::apiResource('rows.trees', TreeController::class)->shallow();
    Route::apiResource('fields.blocks', BlockController::class)->shallow();
    Route::apiResource('farms.pumps', PumpController::class)->shallow();
    Route::apiResource('pumps.valves', ValveController::class)->shallow();
});
