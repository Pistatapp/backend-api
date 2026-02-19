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
        $rawData = $request->getContent();

        if (empty($rawData)) {
            return response()->json([], 422);
        }

        ProcessGpsData::dispatch($rawData)->afterCommit();

        return response()->json([], 200);
    }
}
