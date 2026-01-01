<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActiveLabourResource;
use App\Models\Farm;
use App\Services\ActiveLabourService;
use Illuminate\Http\Request;

class HumanResourcesMapController extends Controller
{
    public function __construct(
        private ActiveLabourService $activeLabourService
    ) {}

    /**
     * Get active labours with GPS data for map display
     */
    public function getActiveLabours(Farm $farm)
    {
        $activeLabours = $this->activeLabourService->getActiveLabours($farm);
        return ActiveLabourResource::collection($activeLabours);
    }
}
