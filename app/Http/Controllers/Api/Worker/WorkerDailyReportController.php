<?php

namespace App\Http\Controllers\Api\Worker;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkerDailyReportResource;
use App\Models\WorkerDailyReport;
use App\Services\WorkerDailyReportApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class WorkerDailyReportController extends Controller
{
    public function __construct(
        private WorkerDailyReportApprovalService $approvalService
    ) {}

    /**
     * Display a listing of daily reports (inbox)
     */
    public function index(Request $request)
    {
        $query = WorkerDailyReport::with('employee')
            ->where('status', 'pending');

        // Filter by date range
        if ($request->has('from_date')) {
            $query->where('date', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->where('date', '<=', $request->to_date);
        }

        // Filter by employee
        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $reports = $query->orderBy('date', 'desc')->paginate();
        return WorkerDailyReportResource::collection($reports);
    }

    /**
     * Display the specified daily report
     */
    public function show(WorkerDailyReport $workerDailyReport)
    {
        $workerDailyReport->load('employee', 'approver');
        return new WorkerDailyReportResource($workerDailyReport);
    }

    /**
     * Update the specified daily report (admin edits)
     */
    public function update(Request $request, WorkerDailyReport $workerDailyReport)
    {
        $validated = $request->validate([
            'admin_added_hours' => 'nullable|numeric|min:0',
            'admin_reduced_hours' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:pending,approved,rejected',
            'notes' => 'nullable|string|max:300',
        ]);

        $this->approvalService->updateReport($workerDailyReport, $validated);

        return new WorkerDailyReportResource($workerDailyReport->fresh()->load('employee', 'approver'));
    }

    /**
     * Approve the daily report
     */
    public function approve(WorkerDailyReport $workerDailyReport)
    {
        $this->approvalService->approve($workerDailyReport, Auth::user());

        return response()->json([
            'message' => 'Report approved successfully',
            'report' => new WorkerDailyReportResource($workerDailyReport->fresh()->load('employee', 'approver'))
        ]);
    }
}
