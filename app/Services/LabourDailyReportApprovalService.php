<?php

namespace App\Services;

use App\Models\LabourDailyReport;
use App\Models\User;

class LabourDailyReportApprovalService
{
    /**
     * Approve a daily report
     *
     * @param LabourDailyReport $report
     * @param User $approver
     * @return void
     */
    public function approve(LabourDailyReport $report, User $approver): void
    {
        // Calculate final total daily work hours including additions/reductions
        $finalWorkHours = $report->actual_work_hours 
            + $report->admin_added_hours 
            - $report->admin_reduced_hours;

        // Update report
        $report->update([
            'status' => 'approved',
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'actual_work_hours' => max(0, $finalWorkHours), // Ensure non-negative
        ]);
    }

    /**
     * Update report with admin adjustments
     *
     * @param LabourDailyReport $report
     * @param array $data
     * @return void
     */
    public function updateReport(LabourDailyReport $report, array $data): void
    {
        $report->update([
            'admin_added_hours' => $data['admin_added_hours'] ?? $report->admin_added_hours,
            'admin_reduced_hours' => $data['admin_reduced_hours'] ?? $report->admin_reduced_hours,
            'status' => $data['status'] ?? $report->status,
            'notes' => $data['notes'] ?? $report->notes,
        ]);
    }
}

