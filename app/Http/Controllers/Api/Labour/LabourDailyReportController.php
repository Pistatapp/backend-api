<?php

namespace App\Http\Controllers\Api\Labour;

use App\Http\Controllers\Controller;
use App\Http\Resources\LabourDailyReportResource;
use App\Models\LabourDailyReport;
use App\Services\LabourDailyReportApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class LabourDailyReportController extends Controller
{
    public function __construct(
        private LabourDailyReportApprovalService $approvalService
    ) {}

    /**
     * Display a listing of daily reports (inbox)
     */
    public function index(Request $request)
    {
        $query = LabourDailyReport::with('labour')
            ->where('status', 'pending');

        // Filter by date range
        if ($request->has('from_date')) {
            $query->where('date', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->where('date', '<=', $request->to_date);
        }

        // Filter by labour
        if ($request->has('labour_id')) {
            $query->where('labour_id', $request->labour_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $reports = $query->orderBy('date', 'desc')->paginate();
        return LabourDailyReportResource::collection($reports);
    }

    /**
     * Display the specified daily report
     */
    public function show(LabourDailyReport $labourDailyReport)
    {
        $labourDailyReport->load('labour', 'approver');
        return new LabourDailyReportResource($labourDailyReport);
    }

    /**
     * Update the specified daily report (admin edits)
     */
    public function update(Request $request, LabourDailyReport $labourDailyReport)
    {
        $validated = $request->validate([
            'admin_added_hours' => 'nullable|numeric|min:0',
            'admin_reduced_hours' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:pending,approved,rejected',
            'notes' => 'nullable|string|max:300',
        ]);

        $this->approvalService->updateReport($labourDailyReport, $validated);

        return new LabourDailyReportResource($labourDailyReport->fresh()->load('labour', 'approver'));
    }

    /**
     * Approve the daily report
     */
    public function approve(LabourDailyReport $labourDailyReport)
    {
        $this->approvalService->approve($labourDailyReport, Auth::user());

        return response()->json([
            'message' => 'Report approved successfully',
            'report' => new LabourDailyReportResource($labourDailyReport->fresh()->load('labour', 'approver'))
        ]);
    }
}

