<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActiveLabourResource;
use App\Http\Resources\LabourPathResource;
use App\Http\Resources\LabourStatusResource;
use App\Models\Farm;
use App\Models\Labour;
use App\Services\ActiveLabourService;
use App\Services\LabourPathService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ActiveLabourController extends Controller
{
    public function __construct(
        private ActiveLabourService $activeLabourService,
        private LabourPathService $labourPathService
    ) {}

    /**
     * Get active labours for a farm
     */
    public function index(Farm $farm)
    {
        $activeLabours = $this->activeLabourService->getActiveLabours($farm);
        // Service already returns formatted arrays, so return directly
        return response()->json(['data' => $activeLabours]);
    }

    /**
     * Get labour path for a specific date
     */
    public function getPath(Labour $labour, Request $request)
    {
        $date = $request->input('date', Carbon::today()->toDateString());
        $carbonDate = Carbon::parse($date);

        $path = $this->labourPathService->getLabourPath($labour, $carbonDate);
        // Service already returns formatted arrays, so return directly
        return response()->json(['data' => $path]);
    }

    /**
     * Get current status of a labour
     */
    public function getCurrentStatus(Labour $labour)
    {
        $latestPoint = $this->labourPathService->getLatestPoint($labour);
        
        // Load active session as relationship
        $labour->setRelation('activeAttendanceSession', 
            $labour->attendanceSessions()
                ->whereDate('date', Carbon::today())
                ->where('status', 'in_progress')
                ->first()
        );
        
        // Set latest GPS as attribute
        $labour->setAttribute('latest_gps', $latestPoint);

        return new LabourStatusResource($labour);
    }
}

