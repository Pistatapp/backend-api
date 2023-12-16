<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\V1\ProfileController;
use Illuminate\Http\Request;
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

Route::controller(AuthController::class)->prefix('auth')->as('auth.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::post('/send', 'sendToken')->name('send');
        Route::post('/verify', 'verifyToken')->name('verify');
    });
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum')->name('logout');
});


Route::middleware(['auth:sanctum', 'last.activity'])->group(function () {
    Route::apiSingleton('profile', ProfileController::class);
});
