<?php

namespace Tests\Feature\Services;

use Tests\TestCase;
use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Models\User;
use App\Models\TractorTask;
use App\Models\Field;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class TaskSpecificDailyReportTest extends TestCase
{
    use RefreshDatabase;

    private GpsDevice $device;
    private User $user;
    private Tractor $tractor;
    private TractorTask $task1;
    private TractorTask $task2;
    private Field $field1;
    private Field $field2;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable debug logging for tests
        \Illuminate\Support\Facades\Log::spy();

        \Illuminate\Support\Facades\Log::info('Starting TaskSpecificDailyReportTest');

        // Set test time accounting for ParseDataService's timezone adjustment
        Carbon::setTestNow('2024-01-24 10:32:00'); // 07:02 + 3:30 timezone offset

        $this->user = User::factory()->create();
        $this->tractor = Tractor::factory()->create([
            'start_work_time' => '06:00',
            'end_work_time' => '18:00',
        ]);

        $this->device = GpsDevice::factory()->create([
            'user_id' => $this->user->id,
            'tractor_id' => $this->tractor->id,
            'imei' => '863070043386100'
        ]);

        // Create two fields with different coordinates (in decimal degrees)
        $this->field1 = Field::factory()->create([
            'coordinates' => [
                [34.884065, 50.599625], // Point 1
                [34.884075, 50.599625], // Point 2
                [34.884075, 50.599635], // Point 3
                [34.884065, 50.599635], // Point 4
                [34.884065, 50.599625], // Close the polygon
            ]
        ]);

        $this->field2 = Field::factory()->create([
            'coordinates' => [
                [34.884480, 50.599770], // Point 1
                [34.884500, 50.599770], // Point 2
                [34.884500, 50.599790], // Point 3
                [34.884480, 50.599790], // Point 4
                [34.884480, 50.599770], // Close the polygon
            ]
        ]);

        // Create tasks with fixed time windows for the test
        $this->task1 = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'field_id' => $this->field1->id,
            'date' => now()->toDateString(),
            'start_time' => '07:00',
            'end_time' => '12:00',
        ]);

        $this->task2 = TractorTask::factory()->create([
            'tractor_id' => $this->tractor->id,
            'field_id' => $this->field2->id,
            'date' => now()->toDateString(),
            'start_time' => '13:00',
            'end_time' => '16:00',
        ]);
    }

    #[Test]
    public function it_creates_separate_daily_reports_for_different_tasks()
    {
        // Force task1 to start since we're within its time window
        $this->task1->update(['status' => 'started']);

        // Send GPS reports for first task area - coordinates within field1
        $this->postJson('/api/gps/reports', [[
            'data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240124,070000,020,000,1,863070043386100'
        ]]);

        // Assert first daily report was created
        $firstTaskReport = $this->tractor->gpsDailyReports()
            ->where('tractor_task_id', $this->task1->id)
            ->where('date', today())
            ->first();

        $this->assertNotNull($firstTaskReport, 'First task daily report was not created');
        $this->assertEquals($this->task1->id, $firstTaskReport->tractor_task_id);

        // Move time forward to second task
        $this->travel(4)->hours();

        // Update second task status since we're now in its time window
        $this->task2->update(['status' => 'started']);
        $this->task1->update(['status' => 'finished']);

        // Send GPS reports for second task area - coordinates within field2
        $this->postJson('/api/gps/reports', [[
            'data' => '+Hooshnic:V1.03,3453.04485,05035.9777,000,240124,110000,020,000,1,863070043386100'
        ]]);

        // Assert second daily report was created
        $secondTaskReport = $this->tractor->gpsDailyReports()
            ->where('tractor_task_id', $this->task2->id)
            ->where('date', today())
            ->first();

        $this->assertNotNull($secondTaskReport, 'Second task daily report was not created');
        $this->assertEquals($this->task2->id, $secondTaskReport->tractor_task_id);
        $this->assertNotEquals($firstTaskReport->id, $secondTaskReport->id);
    }

    #[Test]
    public function it_handles_gps_reports_outside_task_areas()
    {
        // Send GPS report way outside any task area
        $this->postJson('/api/gps/reports', [[
            'data' => '+Hooshnic:V1.03,3553.05000,05135.9800,000,240124,070000,020,000,1,863070043386100'
        ]]);

        // Verify no task-specific daily reports were created
        $taskReports = $this->tractor->gpsDailyReports()
            ->whereNotNull('tractor_task_id')
            ->where('date', today())
            ->get();

        $this->assertCount(0, $taskReports, 'No task-specific reports should be created for GPS data outside task areas.');

        // Verify a default daily report was created without a task ID
        $defaultReport = $this->tractor->gpsDailyReports()
            ->whereNull('tractor_task_id')
            ->where('date', today())
            ->first();

        $this->assertNotNull($defaultReport, 'Default daily report was not created');
        $this->assertNull($defaultReport->tractor_task_id, 'Default report should not be associated with any task.');
    }

    #[Test]
    public function it_accumulates_metrics_separately_for_each_task()
    {
        // Force task1 to start since we're within its time window
        $this->task1->update(['status' => 'started']);

        // Send multiple GPS reports for first task - coordinates within field1
        $this->postJson('/api/gps/reports', [
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240124,070000,015,000,1,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.04394,05035.9776,000,240124,070100,015,000,1,863070043386100']
        ]);

        $firstTaskReport = $this->tractor->gpsDailyReports()
            ->where('tractor_task_id', $this->task1->id)
            ->where('date', today())
            ->first();

        $this->assertNotNull($firstTaskReport, 'First task daily report was not created');
        $this->assertGreaterThanOrEqual(0, $firstTaskReport->traveled_distance, 'Traveled distance should be initialized to 0 or more.');
        $initialDistance = $firstTaskReport->traveled_distance;

        // Move time forward to second task
        $this->travel(4)->hours();

        // Update second task status since we're now in its time window
        $this->task2->update(['status' => 'started']);
        $this->task1->update(['status' => 'finished']);

        // Send multiple GPS reports for second task - coordinates within field2
        $this->postJson('/api/gps/reports', [
            ['data' => '+Hooshnic:V1.03,3453.06880,05035.9862,000,240124,110000,015,000,1,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.06900,05035.9874,000,240124,110100,015,000,1,863070043386100']
        ]);

        // Refresh first task report and verify its metrics haven't changed
        $firstTaskReport->refresh();
        $this->assertEquals($initialDistance, $firstTaskReport->traveled_distance, 'First task metrics should remain unchanged.');

        // Verify second task has its own metrics
        $secondTaskReport = $this->tractor->gpsDailyReports()
            ->where('tractor_task_id', $this->task2->id)
            ->where('date', today())
            ->first();

        $this->assertNotNull($secondTaskReport, 'Second task daily report was not created');
        $this->assertGreaterThan(0, $secondTaskReport->traveled_distance, 'Second task traveled distance should be greater than 0.');
        $this->assertNotEquals($firstTaskReport->traveled_distance, $secondTaskReport->traveled_distance, 'Metrics for tasks should be independent.');
    }

    #[Test]
    public function it_correctly_loads_task_area_coordinates()
    {
        // Verify task1's area matches field1's coordinates
        $this->assertEquals(
            $this->field1->coordinates,
            $this->task1->field->coordinates,
            'Task 1 area coordinates should match field 1 coordinates'
        );

        // Verify task2's area matches field2's coordinates
        $this->assertEquals(
            $this->field2->coordinates,
            $this->task2->fetchTaskArea(),
            'Task 2 area coordinates should match field 2 coordinates'
        );

        // Verify the coordinate format is correct (each point should be [latitude, longitude])
        foreach ($this->task1->fetchTaskArea() as $point) {
            $this->assertCount(2, $point, 'Each coordinate point should have latitude and longitude');
            $this->assertIsFloat($point[0], 'Latitude should be a float');
            $this->assertIsFloat($point[1], 'Longitude should be a float');
        }

        // Check if coordinates form a valid polygon (at least 3 points)
        $this->assertGreaterThanOrEqual(
            3,
            count($this->task1->fetchTaskArea()),
            'Task area should have at least 3 points to form a valid polygon'
        );
    }
}
