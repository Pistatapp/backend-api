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

        $rawData = $this->sanitizeUtf8($rawData);

        ProcessGpsData::dispatch($rawData);

        return response()->json([], 200);
    }

    private function sanitizeUtf8(string $data): string
    {
        if (mb_check_encoding($data, 'UTF-8')) {
            return $data;
        }

        $sanitized = mb_convert_encoding($data, 'UTF-8', 'UTF-8');

        if ($sanitized === false) {
            $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data);
            $sanitized = iconv('UTF-8', 'UTF-8//IGNORE', $sanitized);
        }

        return $sanitized ?: '';
    }
}
