<?php

use Illuminate\Support\Facades\Route;
use App\Services\GpsDataAnalyzer;

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

// GPS Data Analyzer Test Route
Route::get('/gps-test', function () {
    $filePath = '/Users/abbasajorloo/Downloads/gps_raw_data_863070044742822_2025-10-20.txt';

    if (!file_exists($filePath)) {
        return response()->json([
            'error' => 'GPS data file not found',
            'path' => $filePath,
        ], 404);
    }

    try {
        $analyzer = new GpsDataAnalyzer();
        $results = $analyzer
            ->loadFromFile($filePath)
            ->analyze();

        $chronological = $analyzer->getChronologicalDetails();

        return view('gps-test-results', [
            'results' => $results,
            'chronological' => $chronological,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Failed to analyze GPS data',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ], 500);
    }
});

// GPS Data Analyzer JSON API Route
Route::get('/api/gps-test', function () {
    $filePath = '/Users/abbasajorloo/Downloads/gps_raw_data_861826074262375_2025-10-20.txt';

    if (!file_exists($filePath)) {
        return response()->json([
            'error' => 'GPS data file not found',
            'path' => $filePath,
        ], 404);
    }

    try {
        $analyzer = new GpsDataAnalyzer();
        $results = $analyzer
            ->loadFromFile($filePath)
            ->analyze();

        $movements = $analyzer->getMovementDetails();
        $stoppages = $analyzer->getStoppageDetails();
        $chronological = $analyzer->getChronologicalDetails();

        return response()->json([
            'success' => true,
            'summary' => $results,
            'movements' => $movements,
            'stoppages' => $stoppages,
            'chronological' => $chronological,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => 'Failed to analyze GPS data',
            'message' => $e->getMessage(),
        ], 500);
    }
});
