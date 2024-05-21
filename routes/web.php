<?php

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
    // event(new \App\Events\Test());
    $data = "[{\"data\":\"+Hooshnic:V1.04,3453.39700,05033.2955,000,240521,044142,002,015,1,863070046107701\"}]\r\n......";
    $data = rtrim($data, ".");
    $data = json_decode($data, true);
    return is_string($data) ? $data : 'Data is not a string';
    return view('welcome');
});
