<?php

namespace App\Services;

use App\Models\AttendanceDailyReport;
use App\Models\User;

class AttendanceDailyReportApprovalService
{
    /**
     * Approve a daily report
     *
     * @param AttendanceDailyReport $report
     * @param User $approver
     * @return void
     */
    public function approve(AttendanceDailyReport $report, User $approver): void
    {
        $finalWorkHours = $report->actual_work_hours
            + $report->admin_added_hours
            - $report->admin_reduced_hours;

        $report->update([
            'status' => 'approved',
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'actual_work_hours' => max(0, $finalWorkHours),
        ]);
    }

    /**
     * Update report with admin adjustments
     *
     * @param AttendanceDailyReport $report
     * @param array $data
     * @return void
     */
    public function updateReport(AttendanceDailyReport $report, array $data): void
    {
        $report->update([
            'admin_added_hours' => $data['admin_added_hours'] ?? $report->admin_added_hours,
            'admin_reduced_hours' => $data['admin_reduced_hours'] ?? $report->admin_reduced_hours,
            'status' => $data['status'] ?? $report->status,
            'notes' => $data['notes'] ?? $report->notes,
        ]);
    }
}
