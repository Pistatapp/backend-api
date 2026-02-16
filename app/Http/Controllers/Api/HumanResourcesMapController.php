<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActiveUserAttendanceResource;
use App\Models\Farm;
use App\Services\ActiveUserAttendanceService;
use Illuminate\Http\Request;

class HumanResourcesMapController extends Controller
{
    public function __construct(
        private ActiveUserAttendanceService $activeUserAttendanceService
    ) {}

    /**
     * Get active users with attendance tracking for map display
     */
    public function getActiveUsers(Farm $farm)
    {
        $activeUsers = $this->activeUserAttendanceService->getActiveUsers($farm);
        return ActiveUserAttendanceResource::collection($activeUsers);
    }
}
