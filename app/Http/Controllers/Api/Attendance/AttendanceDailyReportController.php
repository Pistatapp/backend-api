<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceDailyReportResource;
use App\Models\AttendanceDailyReport;
use App\Services\AttendanceDailyReportApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AttendanceDailyReportController extends Controller
{
    public function __construct(
        private AttendanceDailyReportApprovalService $approvalService,
    ) {}

    public function index(Request $request)
    {
        $query = AttendanceDailyReport::with('user.profile')
            ->where('status', 'pending');

        if ($request->has('from_date')) {
            $query->where('date', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->where('date', '<=', $request->to_date);
        }
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $reports = $query->orderBy('date', 'desc')->paginate();
        return AttendanceDailyReportResource::collection($reports);
    }

    public function show(AttendanceDailyReport $attendanceDailyReport)
    {
        $attendanceDailyReport->load('user.profile', 'approver');
        return new AttendanceDailyReportResource($attendanceDailyReport);
    }

    public function update(Request $request, AttendanceDailyReport $attendanceDailyReport)
    {
        $validated = $request->validate([
            'admin_added_hours' => 'nullable|numeric|min:0',
            'admin_reduced_hours' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:pending,approved,rejected',
            'notes' => 'nullable|string|max:300',
        ]);

        $this->approvalService->updateReport($attendanceDailyReport, $validated);

        return new AttendanceDailyReportResource($attendanceDailyReport->fresh()->load('user.profile', 'approver'));
    }

    public function approve(AttendanceDailyReport $attendanceDailyReport)
    {
        $this->approvalService->approve($attendanceDailyReport, Auth::user());

        return response()->json([
            'message' => 'Report approved successfully',
            'report' => new AttendanceDailyReportResource($attendanceDailyReport->fresh()->load('user.profile', 'approver')),
        ]);
    }
}
