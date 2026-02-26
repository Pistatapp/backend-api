<?php

use App\Http\Controllers\TelescopeLoginController;
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

/*
|--------------------------------------------------------------------------
| Telescope Login (mobile + one-time code)
|--------------------------------------------------------------------------
*/
Route::prefix('telescope-auth')->name('telescope.')->group(function () {
    Route::get('login', [TelescopeLoginController::class, 'showLoginForm'])->name('login');
    Route::post('login', [TelescopeLoginController::class, 'sendCode'])->name('send-code')->middleware('throttle:5,1');
    Route::get('verify', [TelescopeLoginController::class, 'showVerifyForm'])->name('verify.form');
    Route::post('verify', [TelescopeLoginController::class, 'verify'])->name('verify');
});
