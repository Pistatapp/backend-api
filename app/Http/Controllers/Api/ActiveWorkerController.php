<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActiveWorkerResource;
use App\Http\Resources\WorkerPathResource;
use App\Http\Resources\WorkerStatusResource;
use App\Models\Farm;
use App\Models\Employee;
use App\Services\ActiveWorkerService;
use App\Services\WorkerPathService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ActiveWorkerController extends Controller
{
    public function __construct(
        private ActiveWorkerService $activeWorkerService,
        private WorkerPathService $workerPathService
    ) {}

    /**
     * Get active workers for a farm
     */
    public function index(Farm $farm)
    {
        $activeWorkers = $this->activeWorkerService->getActiveWorkers($farm);
        // Service already returns formatted arrays, so return directly
        return response()->json(['data' => $activeWorkers]);
    }

    /**
     * Get worker path for a specific date
     */
    public function getPath(Employee $employee, Request $request)
    {
        $date = $request->input('date', Carbon::today()->toDateString());
        $carbonDate = Carbon::parse($date);

        $path = $this->workerPathService->getWorkerPath($employee, $carbonDate);
        // Service already returns formatted arrays, so return directly
        return response()->json(['data' => $path]);
    }

    /**
     * Get current status of a worker
     */
    public function getCurrentStatus(Employee $employee)
    {
        $latestPoint = $this->workerPathService->getLatestPoint($employee);
        
        // Load active session as relationship
        $employee->setRelation('activeAttendanceSession', 
            $employee->attendanceSessions()
                ->whereDate('date', Carbon::today())
                ->where('status', 'in_progress')
                ->first()
        );
        
        // Set latest GPS as attribute
        $employee->setAttribute('latest_gps', $latestPoint);

        return new WorkerStatusResource($employee);
    }
}
