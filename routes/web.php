<?php

use App\Http\Controllers\TelescopeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Telescope Authentication Routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [TelescopeController::class, 'showLoginForm'])->name('telescope.login');
    Route::post('/send-token', [TelescopeController::class, 'sendToken'])->name('telescope.send-token');
    Route::get('/verify', [TelescopeController::class, 'showVerifyForm'])->name('telescope.verify');
    Route::post('/verify-token', [TelescopeController::class, 'verifyToken'])->name('telescope.verify-token');
});
