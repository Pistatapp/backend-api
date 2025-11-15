<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActiveWorkerResource;
use App\Models\Farm;
use App\Services\ActiveWorkerService;
use Illuminate\Http\Request;

class HumanResourcesMapController extends Controller
{
    public function __construct(
        private ActiveWorkerService $activeWorkerService
    ) {}

    /**
     * Get active workers with GPS data for map display
     */
    public function getActiveWorkers(Farm $farm)
    {
        $activeWorkers = $this->activeWorkerService->getActiveWorkers($farm);
        return ActiveWorkerResource::collection($activeWorkers);
    }
}
