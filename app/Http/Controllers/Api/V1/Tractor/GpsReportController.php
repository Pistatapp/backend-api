<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Http\Requests\GpsReportRequest;
use App\Jobs\IngestGpsData;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class GpsReportController extends Controller
{
    public function __invoke(GpsReportRequest $request): JsonResponse
    {
        $data = $request->validated('data');

        try {
            IngestGpsData::dispatch($data);
        } catch (\Throwable $e) {
            Log::error('GPS ingest dispatch failed', [
                'imei' => $data[0]['imei'] ?? null,
                'record_count' => count($data),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return response()->json(['success' => true], 200);
    }
}
