<?php

namespace Tests\Feature\Worker;

use App\Models\Labour;
use App\Models\Farm;
use App\Models\User;
use App\Models\LabourDailyReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerDailyReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * Test index returns pending reports.
     */
    public function test_index_returns_pending_reports(): void
    {
        $labour = Labour::factory()->create();

        LabourDailyReport::factory()->create([
            'labour_id' => $labour->id,
            'status' => 'pending',
        ]);

        LabourDailyReport::factory()->create([
            'labour_id' => $labour->id,
            'status' => 'approved',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/labour-daily-reports');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    /**
     * Test index filters by date range.
     */
    public function test_index_filters_by_date_range(): void
    {
        $labour = Labour::factory()->create();

        LabourDailyReport::factory()->create([
            'labour_id' => $labour->id,
            'date' => Carbon::parse('2024-11-10'),
            'status' => 'pending',
        ]);

        LabourDailyReport::factory()->create([
            'labour_id' => $labour->id,
            'date' => Carbon::parse('2024-11-15'),
            'status' => 'pending',
        ]);

        LabourDailyReport::factory()->create([
            'labour_id' => $labour->id,
            'date' => Carbon::parse('2024-11-20'),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/labour-daily-reports?from_date=2024-11-12&to_date=2024-11-18');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    /**
     * Test index filters by employee.
     */
    public function test_index_filters_by_employee(): void
    {
        $labour1 = Labour::factory()->create();
        $labour2 = Labour::factory()->create();

        LabourDailyReport::factory()->create([
            'labour_id' => $labour1->id,
            'status' => 'pending',
        ]);

        LabourDailyReport::factory()->create([
            'labour_id' => $labour2->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/labour-daily-reports?labour_id={$labour1->id}");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    /**
     * Test show returns single report.
     */
    public function test_show_returns_single_report(): void
    {
        $labour = Labour::factory()->create();
        $report = LabourDailyReport::factory()->create([
            'labour_id' => $labour->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/labour-daily-reports/{$report->id}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => $report->id]);
    }

    /**
     * Test update modifies report.
     */
    public function test_update_modifies_report(): void
    {
        $labour = Labour::factory()->create();
        $report = LabourDailyReport::factory()->create([
            'labour_id' => $labour->id,
            'admin_added_hours' => 0,
            'admin_reduced_hours' => 0,
            'notes' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/labour-daily-reports/{$report->id}", [
                'admin_added_hours' => 1.5,
                'admin_reduced_hours' => 0.5,
                'notes' => 'Manual adjustment',
            ]);

        $response->assertStatus(200);

        $report->refresh();
        $this->assertEquals(1.5, $report->admin_added_hours);
        $this->assertEquals(0.5, $report->admin_reduced_hours);
        $this->assertEquals('Manual adjustment', $report->notes);
    }

    /**
     * Test approve approves report.
     */
    public function test_approve_approves_report(): void
    {
        $labour = Labour::factory()->create();
        $report = LabourDailyReport::factory()->create([
            'labour_id' => $labour->id,
            'status' => 'pending',
            'approved_by' => null,
            'approved_at' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/labour-daily-reports/{$report->id}/approve");

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'Report approved successfully']);

        $report->refresh();
        $this->assertEquals('approved', $report->status);
        $this->assertEquals($this->user->id, $report->approved_by);
        $this->assertNotNull($report->approved_at);
    }
}

