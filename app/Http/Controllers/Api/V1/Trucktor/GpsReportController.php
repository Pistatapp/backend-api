<?php

namespace App\Http\Controllers\Api\V1\Trucktor;

use App\Http\Controllers\Controller;
use App\Services\GPSDataFormatter;
use Illuminate\Http\Request;

class GpsReportController extends Controller
{
    public function __construct(
        private GPSDataFormatter $gpsDataFormatter
    ) {
        // 
    }

    public function store(Request $request)
    {
        $data = $request->getContent();
        $data = rtrim($data, '.');

        $data = json_decode($data, true);

        $reports = $this->gpsDataFormatter->format($data);

        return $reports;
    }
}
