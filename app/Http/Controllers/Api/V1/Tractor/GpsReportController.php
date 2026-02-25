<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGpsData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GpsReportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->json('data');

        ProcessGpsData::dispatch($data);

        return response()->json(['success' => true], 200);
    }
}
