<?php

namespace Tests\Unit\Models;

use App\Models\Labour;
use App\Models\User;
use App\Models\LabourDailyReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class WorkerDailyReportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that daily report belongs to labour.
     */
    public function test_daily_report_belongs_to_employee(): void
    {
        $labour = Labour::factory()->create();
        $report = LabourDailyReport::factory()->create(['labour_id' => $labour->id]);

        $this->assertInstanceOf(Labour::class, $report->labour);
        $this->assertEquals($labour->id, $report->labour->id);
    }

    /**
     * Test that daily report belongs to approver (user).
     */
    public function test_daily_report_belongs_to_approver(): void
    {
        $approver = User::factory()->create();
        $report = LabourDailyReport::factory()->create([
            'approved_by' => $approver->id,
            'approved_at' => Carbon::now(),
        ]);

        $this->assertInstanceOf(User::class, $report->approver);
        $this->assertEquals($approver->id, $report->approver->id);
    }

    /**
     * Test that date is cast to date.
     */
    public function test_date_is_cast_to_date(): void
    {
        $date = Carbon::today();
        $report = LabourDailyReport::factory()->create(['date' => $date]);

        $this->assertInstanceOf(Carbon::class, $report->date);
        $this->assertEquals($date->toDateString(), $report->date->toDateString());
    }

    /**
     * Test that numeric fields are cast to decimal.
     */
    public function test_numeric_fields_are_cast_to_decimal(): void
    {
        $report = LabourDailyReport::factory()->create([
            'scheduled_hours' => 8.0,
            'actual_work_hours' => 8.5,
            'overtime_hours' => 0.5,
            'productivity_score' => 95.5,
            'admin_added_hours' => 0.25,
            'admin_reduced_hours' => 0.0,
        ]);

        $this->assertIsNumeric($report->scheduled_hours);
        $this->assertIsNumeric($report->actual_work_hours);
        $this->assertIsNumeric($report->overtime_hours);
        $this->assertIsNumeric($report->productivity_score);
    }

    /**
     * Test that approved_at is cast to datetime.
     */
    public function test_approved_at_is_cast_to_datetime(): void
    {
        $approvedAt = Carbon::now();
        $report = LabourDailyReport::factory()->create(['approved_at' => $approvedAt]);

        $this->assertInstanceOf(Carbon::class, $report->approved_at);
    }

    /**
     * Test daily report can have pending status.
     */
    public function test_daily_report_can_have_pending_status(): void
    {
        $report = LabourDailyReport::factory()->create(['status' => 'pending']);

        $this->assertEquals('pending', $report->status);
        $this->assertNull($report->approved_by);
        $this->assertNull($report->approved_at);
    }

    /**
     * Test daily report can have approved status.
     */
    public function test_daily_report_can_have_approved_status(): void
    {
        $approver = User::factory()->create();
        $approvedAt = Carbon::now();

        $report = LabourDailyReport::factory()->create([
            'status' => 'approved',
            'approved_by' => $approver->id,
            'approved_at' => $approvedAt,
        ]);

        $this->assertEquals('approved', $report->status);
        $this->assertEquals($approver->id, $report->approved_by);
    }
}

