<?php

namespace Tests\Feature\Services;

use App\Models\Field;
use App\Models\Operation;
use App\Models\Tractor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class TractorReportsFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tractor $tractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    #[Test]
    public function it_filters_reports_by_tractor_and_date(): void
    {
        $tractor = Tractor::factory()->create();
        $gregorianDate = '2025-03-23';
        $persianDate = '1404/01/03'; // equivalent to 2025-03-23

        // Create operations and fields first
        $operations = Operation::factory()->count(3)->create();
        $fields = [];
        for ($i = 0; $i < 3; $i++) {
            $fields[] = \App\Models\Field::factory()->create([
                'farm_id' => $tractor->farm_id
            ]);
        }

        // Create 3 different tasks and their corresponding GPS daily reports
        $startTime = strtotime('08:00');
        $endTime = $startTime + 7200; // 2 hours interval

        for ($i = 0; $i < 3; $i++) {
            $task = $tractor->tasks()->create([
                'operation_id' => $operations[$i]->id,
                'taskable_type' => Field::class,
                'taskable_id' => $fields[$i]->id,
                'date' => $gregorianDate, // Store in Gregorian format in database
                'start_time' => date('H:i', $startTime),
                'end_time' => date('H:i', $endTime),
                'created_by' => $this->user->id,
                'status' => 'finished'
            ]);

            $task->gpsMetricsCalculation()->create([
                'tractor_id' => $tractor->id,
                'traveled_distance' => 100 * ($i + 1),
                'work_duration' => 3600 * ($i + 1),
                'stoppage_count' => 5 * ($i + 1),
                'stoppage_duration' => 1200 * ($i + 1),
                'average_speed' => 20 * ($i + 1),
                'efficiency' => 80 - (($i + 1) * 5),
                'date' => $gregorianDate,
            ]);

            $startTime = $endTime;
            $endTime = $startTime + 7200; // 2 hours interval
        }

        // Filter reports by tractor and Persian date
        $response = $this->postJson(route('tractor_reports.filter', [
            'tractor_id' => $tractor->id,
            'date' => $persianDate,
        ]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'reports' => [
                    '*' => [
                        'date',
                        'traveled_distance',
                        'avg_speed',
                        'work_duration',
                        'stoppage_duration',
                        'stoppage_count',
                        'task' => [
                            'operation' => [
                                'id',
                                'name',
                            ],
                            'taskable' => [
                                'id',
                                'name',
                                'type',
                            ],
                            'consumed_water',
                            'consumed_fertilizer',
                            'consumed_poison',
                            'operation_area',
                            'workers_count',
                        ],
                    ],
                ],
                'accumulated' => [
                    'traveled_distance',
                    'avg_speed',
                    'work_duration',
                    'stoppage_duration',
                    'stoppage_count',
                ],
                'expectations' => [
                    'expected_daily_work',
                    'total_work_duration',
                    'total_efficiency',
                ],
            ],
        ]);

        // Validate individual reports
        $responseData = $response->json('data');
        $reports = $responseData['reports'];

        $this->assertCount(3, $reports);

        // Validate first report values
        $this->assertEquals('100.00', $reports[0]['traveled_distance']);
        $this->assertEquals('20.00', $reports[0]['avg_speed']);
        $this->assertEquals('01:00:00', $reports[0]['work_duration']);
        $this->assertEquals('00:20:00', $reports[0]['stoppage_duration']);
        $this->assertEquals(5, $reports[0]['stoppage_count']);
        $this->assertEquals($operations[0]->name, $reports[0]['task']['operation']['name']);
        $this->assertEquals($fields[0]->name, $reports[0]['task']['taskable']['name']);

        // Validate second report values
        $this->assertEquals('200.00', $reports[1]['traveled_distance']);
        $this->assertEquals('40.00', $reports[1]['avg_speed']);
        $this->assertEquals('02:00:00', $reports[1]['work_duration']);
        $this->assertEquals('00:40:00', $reports[1]['stoppage_duration']);
        $this->assertEquals(10, $reports[1]['stoppage_count']);
        $this->assertEquals($operations[1]->name, $reports[1]['task']['operation']['name']);
        $this->assertEquals($fields[1]->name, $reports[1]['task']['taskable']['name']);

        // Validate third report values
        $this->assertEquals($operations[2]->name, $reports[2]['task']['operation']['name']);
        $this->assertEquals($fields[2]->name, $reports[2]['task']['taskable']['name']);
        $this->assertEquals('300.00', $reports[2]['traveled_distance']);
        $this->assertEquals('60.00', $reports[2]['avg_speed']);
        $this->assertEquals('03:00:00', $reports[2]['work_duration']);
        $this->assertEquals('01:00:00', $reports[2]['stoppage_duration']);
        $this->assertEquals(15, $reports[2]['stoppage_count']);

        // Validate accumulated values
        $accumulated = $responseData['accumulated'];
        $this->assertEquals('600.00', $accumulated['traveled_distance']); // 100 + 200 + 300
        $this->assertEquals('40.00', $accumulated['avg_speed']); // Average of all average speeds
        $this->assertEquals('06:00:00', $accumulated['work_duration']); // Sum of all work durations
        $this->assertEquals('02:00:00', $accumulated['stoppage_duration']); // Sum of all stoppage durations
        $this->assertEquals(30, $accumulated['stoppage_count']); // Sum of all stoppage counts

        // Validate expectations
        $expectations = $responseData['expectations'];
        $this->assertEquals('08:00:00', $expectations['expected_daily_work']); // 8 hours in seconds
        $this->assertEquals('06:00:00', $expectations['total_work_duration']); // Sum of all work durations
        $this->assertEquals('75.00', $expectations['total_efficiency']); // (21600 / 28800) * 100
    }

    #[Test]
    public function it_filters_reports_by_tractor_operation_and_date(): void
    {
        $tractor = Tractor::factory()->create();
        $gregorianDate = '2025-03-23';
        $persianDate = '1404/01/03'; // equivalent to 2025-03-23

        // Create operations and fields
        $operation = Operation::factory()->create();
        $otherOperation = Operation::factory()->create();
        $fields = \App\Models\Field::factory()
            ->count(3)
            ->create(['farm_id' => $tractor->farm_id]);

        // Create tasks with the specified operation
        $startTime = strtotime('08:00');
        $endTime = $startTime + 7200; // 2 hours interval

        for ($i = 0; $i < 2; $i++) {
            $task = $tractor->tasks()->create([
                'operation_id' => $operation->id,
                'taskable_type' => Field::class,
                'taskable_id' => $fields[$i]->id,
                'date' => $gregorianDate, // Store in Gregorian format in database
                'start_time' => date('H:i', $startTime),
                'end_time' => date('H:i', $endTime),
                'created_by' => $this->user->id,
                'status' => 'finished'
            ]);

            $task->gpsMetricsCalculation()->create([
                'tractor_id' => $tractor->id,
                'traveled_distance' => 100 * ($i + 1),
                'work_duration' => 3600 * ($i + 1),
                'stoppage_count' => 5 * ($i + 1),
                'stoppage_duration' => 1200 * ($i + 1),
                'average_speed' => 20 * ($i + 1),
                'efficiency' => 80 - (($i + 1) * 5),
                'date' => $gregorianDate,
            ]);

            $startTime = $endTime;
            $endTime = $startTime + 7200;
        }

        // Create a task with different operation (should not be included in results)
        $task = $tractor->tasks()->create([
            'operation_id' => $otherOperation->id,
            'taskable_type' => Field::class,
            'taskable_id' => $fields[2]->id,
            'date' => $gregorianDate,
            'start_time' => date('H:i', $startTime),
            'end_time' => date('H:i', $endTime),
            'created_by' => $this->user->id,
            'status' => 'finished'
        ]);

        $task->gpsMetricsCalculation()->create([
            'tractor_id' => $tractor->id,
            'traveled_distance' => 300,
            'work_duration' => 10800,
            'stoppage_count' => 15,
            'stoppage_duration' => 3600,
            'average_speed' => 60,
            'efficiency' => 70,
            'date' => $gregorianDate,
        ]);

        // Filter reports by tractor, operation and Persian date
        $response = $this->postJson(route('tractor_reports.filter', [
            'tractor_id' => $tractor->id,
            'operation' => $operation->id,
            'date' => $persianDate,
        ]));

        $response->assertStatus(200);

        // Validate individual reports
        $responseData = $response->json('data');
        $reports = $responseData['reports'];

        $this->assertCount(2, $reports); // Only reports for the specified operation

        // Validate first report values
        $this->assertEquals('100.00', $reports[0]['traveled_distance']);
        $this->assertEquals('20.00', $reports[0]['avg_speed']);
        $this->assertEquals('01:00:00', $reports[0]['work_duration']);
        $this->assertEquals('00:20:00', $reports[0]['stoppage_duration']);
        $this->assertEquals(5, $reports[0]['stoppage_count']);

        // Validate task structure for first report
        $this->assertArrayHasKey('task', $reports[0]);
        $this->assertEquals($operation->id, $reports[0]['task']['operation']['id']);
        $this->assertEquals($operation->name, $reports[0]['task']['operation']['name']);
        $this->assertEquals($fields[0]->id, $reports[0]['task']['taskable']['id']);
        $this->assertEquals($fields[0]->name, $reports[0]['task']['taskable']['name']);
        $this->assertEquals('Field', $reports[0]['task']['taskable']['type']);

        // Validate second report values
        $this->assertEquals('200.00', $reports[1]['traveled_distance']);
        $this->assertEquals('40.00', $reports[1]['avg_speed']);
        $this->assertEquals('02:00:00', $reports[1]['work_duration']);
        $this->assertEquals('00:40:00', $reports[1]['stoppage_duration']);
        $this->assertEquals(10, $reports[1]['stoppage_count']);

        // Validate task structure for second report
        $this->assertArrayHasKey('task', $reports[1]);
        $this->assertEquals($operation->id, $reports[1]['task']['operation']['id']);
        $this->assertEquals($operation->name, $reports[1]['task']['operation']['name']);
        $this->assertEquals($fields[1]->id, $reports[1]['task']['taskable']['id']);
        $this->assertEquals($fields[1]->name, $reports[1]['task']['taskable']['name']);
        $this->assertEquals('Field', $reports[1]['task']['taskable']['type']);

        // Validate accumulated values (only for the filtered operation)
        $accumulated = $responseData['accumulated'];
        $this->assertEquals('300.00', $accumulated['traveled_distance']); // 100 + 200
        $this->assertEquals('30.00', $accumulated['avg_speed']); // Average of 20 and 40
        $this->assertEquals('03:00:00', $accumulated['work_duration']); // 3600 + 7200
        $this->assertEquals('01:00:00', $accumulated['stoppage_duration']); // 1200 + 2400
        $this->assertEquals(15, $accumulated['stoppage_count']); // 5 + 10

        // Validate expectations
        $expectations = $responseData['expectations'];
        $this->assertEquals('08:00:00', $expectations['expected_daily_work']); // 8 hours in seconds
        $this->assertEquals('03:00:00', $expectations['total_work_duration']); // Sum of filtered work durations
        $this->assertEquals('37.50', $expectations['total_efficiency']); // (10800 / 28800) * 100
    }

    #[Test]
    public function it_filters_reports_by_tractor_and_current_month(): void
    {
        $tractor = Tractor::factory()->create();
        $baseDate = '2024-03-15';

        // Create tasks for current month
        $currentMonthTasks = collect([
            ['date' => $baseDate, 'efficiency' => 75],
            ['date' => date('Y-m-d', strtotime($baseDate . ' -5 days')), 'efficiency' => 80],
            ['date' => date('Y-m-d', strtotime($baseDate . ' -10 days')), 'efficiency' => 85],
        ]);

        // Create task for last month (should not be included)
        $lastMonthTask = ['date' => date('Y-m-d', strtotime($baseDate . ' -1 month')), 'efficiency' => 90];

        // Create all tasks
        $operation = Operation::factory()->create();
        $field = \App\Models\Field::factory()->create(['farm_id' => $tractor->farm_id]);

        // Helper function to create task and its report
        $createTaskWithReport = function ($taskData) use ($tractor, $operation, $field) {
            $task = $tractor->tasks()->create([
                'operation_id' => $operation->id,
                'taskable_type' => Field::class,
                'taskable_id' => $field->id,
                'date' => $taskData['date'],
                'start_time' => '08:00',
                'end_time' => '16:00',
                'created_by' => $this->user->id,
                'status' => 'finished'
            ]);

            $task->gpsMetricsCalculation()->create([
                'tractor_id' => $tractor->id,
                'traveled_distance' => 100,
                'work_duration' => 28800, // 8 hours
                'stoppage_count' => 5,
                'stoppage_duration' => 1800,
                'average_speed' => 20,
                'efficiency' => $taskData['efficiency'],
                'date' => $taskData['date'],
            ]);
        };

        // Create tasks
        $currentMonthTasks->each(function ($taskData) use ($createTaskWithReport) {
            $createTaskWithReport($taskData);
        });
        $createTaskWithReport($lastMonthTask);

        // Mock current date to match our test data
        $this->travelTo($baseDate);

        // Test filtering by current month
        $response = $this->postJson(route('tractor_reports.filter', [
            'tractor_id' => $tractor->id,
            'period' => 'month'
        ]));

        $response->assertStatus(200);

        // Verify we only got current month's reports
        $responseData = $response->json('data');
        $reports = $responseData['reports'];

        $this->assertCount(3, $reports);

        // Verify accumulated values
        $accumulated = $responseData['accumulated'];
        $this->assertEquals('300.00', $accumulated['traveled_distance']); // 3 tasks * 100
        $this->assertEquals('24:00:00', $accumulated['work_duration']); // 3 tasks * 28800 seconds
        $this->assertEquals(15, $accumulated['stoppage_count']); // 3 tasks * 5
        $this->assertEquals('01:30:00', $accumulated['stoppage_duration']); // 3 tasks * 1800
    }

    #[Test]
    public function it_filters_reports_by_tractor_and_current_year(): void
    {
        $tractor = Tractor::factory()->create();

        // Create tasks for current year
        $currentYearTasks = collect([
            ['date' => now(), 'efficiency' => 75],
            ['date' => now()->subMonths(2), 'efficiency' => 80],
            ['date' => now()->startOfYear()->addMonth(), 'efficiency' => 85], // Ensure it's in current year
        ]);

        // Create task for last year (should not be included)
        $lastYearTask = ['date' => now()->subYear(), 'efficiency' => 90];

        // Create all tasks
        $operation = Operation::factory()->create();
        $field = \App\Models\Field::factory()->create(['farm_id' => $tractor->farm_id]);

        // Helper function to create task and its report
        $createTaskWithReport = function ($taskData) use ($tractor, $operation, $field) {
            info('Creating task for date: ' . $taskData['date']->format('Y-m-d'));

            $task = $tractor->tasks()->create([
                'operation_id' => $operation->id,
                'taskable_type' => Field::class,
                'taskable_id' => $field->id,
                'date' => $taskData['date']->format('Y-m-d'),
                'start_time' => '08:00',
                'end_time' => '16:00',
                'created_by' => $this->user->id,
                'status' => 'finished'
            ]);

            $task->gpsMetricsCalculation()->create([
                'tractor_id' => $tractor->id,
                'traveled_distance' => 100,
                'work_duration' => 28800, // 8 hours
                'stoppage_count' => 5,
                'stoppage_duration' => 1800,
                'average_speed' => 20,
                'efficiency' => $taskData['efficiency'],
                'date' => $taskData['date']->format('Y-m-d'),
            ]);
        };

        // Create tasks
        $currentYearTasks->each(function ($taskData) use ($createTaskWithReport) {
            $createTaskWithReport($taskData);
        });
        $createTaskWithReport($lastYearTask);

        // Test filtering by current year
        $response = $this->postJson(route('tractor_reports.filter', [
            'tractor_id' => $tractor->id,
            'period' => 'year'
        ]));

        $response->assertStatus(200);

        // Verify we only got current year's reports
        $responseData = $response->json('data');
        $reports = $responseData['reports'];

        $this->assertCount(3, $reports);

        // Verify accumulated values
        $accumulated = $responseData['accumulated'];
        $this->assertEquals('300.00', $accumulated['traveled_distance']); // 3 tasks * 100
        $this->assertEquals('24:00:00', $accumulated['work_duration']); // 3 tasks * 28800 seconds
        $this->assertEquals(15, $accumulated['stoppage_count']); // 3 tasks * 5
        $this->assertEquals('01:30:00', $accumulated['stoppage_duration']); // 3 tasks * 1800
    }

    #[Test]
    public function it_filters_reports_by_tractor_and_specific_month(): void
    {
        $tractor = Tractor::factory()->create();

        // Create tasks for specific month (Farvardin 1404)
        $currentMonthTasks = collect([
            ['date' => '2025-03-22', 'efficiency' => 80], // 2 Farvardin 1404
            ['date' => '2025-03-23', 'efficiency' => 75], // 3 Farvardin 1404
        ]);

        // Create task for previous month (should not be included)
        $previousMonthTask = ['date' => '2025-03-20', 'efficiency' => 85]; // 29 Esfand 1403

        // Create task for next month (should not be included)
        $nextMonthTask = ['date' => '2025-04-21', 'efficiency' => 90]; // 1 Ordibehesht 1404

        // Create all tasks
        $operation = Operation::factory()->create();
        $field = \App\Models\Field::factory()->create(['farm_id' => $tractor->farm_id]);

        // Helper function to create task and its report
        $createTaskWithReport = function ($taskData) use ($tractor, $operation, $field) {
            $task = $tractor->tasks()->create([
                'operation_id' => $operation->id,
                'taskable_type' => Field::class,
                'taskable_id' => $field->id,
                'date' => $taskData['date'],
                'start_time' => '08:00',
                'end_time' => '16:00',
                'created_by' => $this->user->id,
                'status' => 'finished'
            ]);

            $task->gpsMetricsCalculation()->create([
                'tractor_id' => $tractor->id,
                'traveled_distance' => 100,
                'work_duration' => 28800, // 8 hours
                'stoppage_count' => 5,
                'stoppage_duration' => 1800,
                'average_speed' => 20,
                'efficiency' => $taskData['efficiency'],
                'date' => $taskData['date'],
            ]);
        };

        // Create tasks
        $currentMonthTasks->each(function ($taskData) use ($createTaskWithReport) {
            $createTaskWithReport($taskData);
        });
        $createTaskWithReport($previousMonthTask);
        $createTaskWithReport($nextMonthTask);

        // Test filtering by specific month (Farvardin 1404)
        $response = $this->postJson(route('tractor_reports.filter', [
            'tractor_id' => $tractor->id,
            'period' => 'specific_month',
            'month' => '1404/01/01' // First day of Farvardin 1404
        ]));

        $response->assertStatus(200);

        // Verify we only got the specified month's reports
        $responseData = $response->json('data');
        $reports = $responseData['reports'];

        $this->assertCount(2, $reports); // Only reports from Farvardin should be included

        // Verify accumulated values
        $accumulated = $responseData['accumulated'];
        $this->assertEquals('200.00', $accumulated['traveled_distance']); // 2 tasks * 100
        $this->assertEquals('16:00:00', $accumulated['work_duration']); // 2 tasks * 28800 seconds
        $this->assertEquals(10, $accumulated['stoppage_count']); // 2 tasks * 5
        $this->assertEquals('01:00:00', $accumulated['stoppage_duration']); // 2 tasks * 1800
    }

    #[Test]
    public function it_filters_reports_by_tractor_and_persian_year(): void
    {
        $tractor = Tractor::factory()->create();

        // Create tasks for Persian year 1404
        $currentYearTasks = collect([
            ['date' => '2025-03-22', 'efficiency' => 80], // 2 Farvardin 1404
            ['date' => '2025-03-23', 'efficiency' => 75], // 3 Farvardin 1404
            ['date' => '2026-03-20', 'efficiency' => 85], // 29 Esfand 1404
        ]);

        // Create task for previous Persian year (should not be included)
        $lastYearTask = ['date' => '2025-03-20', 'efficiency' => 90]; // 29 Esfand 1403

        // Create task for next Persian year (should not be included)
        $nextYearTask = ['date' => '2026-03-21', 'efficiency' => 70]; // 1 Farvardin 1405

        // Create all tasks
        $operation = Operation::factory()->create();
        $field = \App\Models\Field::factory()->create(['farm_id' => $tractor->farm_id]);

        // Helper function to create task and its report
        $createTaskWithReport = function ($taskData) use ($tractor, $operation, $field) {
            $task = $tractor->tasks()->create([
                'operation_id' => $operation->id,
                'taskable_type' => Field::class,
                'taskable_id' => $field->id,
                'date' => $taskData['date'],
                'start_time' => '08:00',
                'end_time' => '16:00',
                'created_by' => $this->user->id,
                'status' => 'finished'
            ]);

            $task->gpsMetricsCalculation()->create([
                'tractor_id' => $tractor->id,
                'traveled_distance' => 100,
                'work_duration' => 28800, // 8 hours
                'stoppage_count' => 5,
                'stoppage_duration' => 1800,
                'average_speed' => 20,
                'efficiency' => $taskData['efficiency'],
                'date' => $taskData['date'],
            ]);
        };

        // Create tasks
        $currentYearTasks->each(function ($taskData) use ($createTaskWithReport) {
            $createTaskWithReport($taskData);
        });
        $createTaskWithReport($lastYearTask);
        $createTaskWithReport($nextYearTask);

        // Test filtering by Persian year 1404
        $response = $this->postJson(route('tractor_reports.filter', [
            'tractor_id' => $tractor->id,
            'period' => 'persian_year',
            'year' => '1404'
        ]));

        $response->assertStatus(200);

        // Verify we only got the specified Persian year's reports
        $responseData = $response->json('data');
        $reports = $responseData['reports'];

        $this->assertCount(3, $reports); // Only reports from Persian year 1404 should be included

        // Verify accumulated values
        $accumulated = $responseData['accumulated'];
        $this->assertEquals('300.00', $accumulated['traveled_distance']); // 3 tasks * 100
        $this->assertEquals('24:00:00', $accumulated['work_duration']); // 3 tasks * 28800 seconds
        $this->assertEquals(15, $accumulated['stoppage_count']); // 3 tasks * 5
        $this->assertEquals('01:30:00', $accumulated['stoppage_duration']); // 3 tasks * 1800
    }

    #[Test]
    public function it_filters_reports_by_specific_persian_year(): void
    {
        $tractor = Tractor::factory()->create();

        // Create tasks for Persian year 1404
        $year1404Tasks = collect([
            ['date' => '2025-03-22', 'efficiency' => 80], // 2 Farvardin 1404
            ['date' => '2025-03-23', 'efficiency' => 75], // 3 Farvardin 1404
            ['date' => '2026-03-18', 'efficiency' => 85], // 29 Esfand 1404
        ]);

        // Create task for Persian year 1403 (should not be included)
        $year1403Task = ['date' => '2025-03-20', 'efficiency' => 90]; // 29 Esfand 1403

        // Create task for Persian year 1405 (should not be included)
        $year1405Task = ['date' => '2026-03-21', 'efficiency' => 70]; // 1 Farvardin 1405

        // Create all tasks
        $operation = Operation::factory()->create();
        $field = \App\Models\Field::factory()->create(['farm_id' => $tractor->farm_id]);

        // Helper function to create task and its report
        $createTaskWithReport = function ($taskData) use ($tractor, $operation, $field) {
            $task = $tractor->tasks()->create([
                'operation_id' => $operation->id,
                'taskable_type' => Field::class,
                'taskable_id' => $field->id,
                'date' => $taskData['date'],
                'start_time' => '08:00',
                'end_time' => '16:00',
                'created_by' => $this->user->id,
                'status' => 'finished'
            ]);

            $task->gpsMetricsCalculation()->create([
                'tractor_id' => $tractor->id,
                'traveled_distance' => 100,
                'work_duration' => 28800, // 8 hours
                'stoppage_count' => 5,
                'stoppage_duration' => 1800,
                'average_speed' => 20,
                'efficiency' => $taskData['efficiency'],
                'date' => $taskData['date'],
            ]);
        };

        // Create tasks
        $year1404Tasks->each(function ($taskData) use ($createTaskWithReport) {
            $createTaskWithReport($taskData);
        });
        $createTaskWithReport($year1403Task);
        $createTaskWithReport($year1405Task);

        // Test filtering by Persian year 1404
        $response = $this->postJson(route('tractor_reports.filter', [
            'tractor_id' => $tractor->id,
            'period' => 'persian_year',
            'year' => '1404'
        ]));

        $response->assertStatus(200);

        // Verify we only got reports from Persian year 1404
        $responseData = $response->json('data');
        $reports = $responseData['reports'];

        $this->assertCount(3, $reports); // Only reports from Persian year 1404

        // Verify accumulated values
        $accumulated = $responseData['accumulated'];
        $this->assertEquals('300.00', $accumulated['traveled_distance']); // 3 tasks * 100
        $this->assertEquals('24:00:00', $accumulated['work_duration']); // 3 tasks * 28800 seconds
        $this->assertEquals(15, $accumulated['stoppage_count']); // 3 tasks * 5
        $this->assertEquals('01:30:00', $accumulated['stoppage_duration']); // 3 tasks * 1800

        // Verify expectations
        $expectations = $responseData['expectations'];
        $this->assertEquals('08:00:00', $expectations['expected_daily_work']); // 8 hours in seconds
        $this->assertEquals('24:00:00', $expectations['total_work_duration']); // Sum of filtered work durations

        // Calculate efficiency based on actual working days in the period
        $expectedEfficiency = (86400 / (28800 * 3)) * 100; // 3 working days in the test data
        $this->assertEquals(number_format($expectedEfficiency, 2), $expectations['total_efficiency']);
    }

    /**
     * Test that reports are not filtered by operation when operation is not set or is null.
     */
    #[Test]
    public function it_returns_all_reports_when_operation_is_null(): void
    {
        $tractor = Tractor::factory()->create();
        $gregorianDate = '2025-03-23';
        $persianDate = '1404/01/03'; // equivalent to 2025-03-23

        // Create operations and fields
        $operation = Operation::factory()->create();
        $otherOperation = Operation::factory()->create();
        $fields = \App\Models\Field::factory()
            ->count(3)
            ->create(['farm_id' => $tractor->farm_id]);

        // Create tasks with different operations
        $startTime = strtotime('08:00');
        $endTime = $startTime + 7200; // 2 hours interval

        for ($i = 0; $i < 2; $i++) {
            $task = $tractor->tasks()->create([
                'operation_id' => $operation->id,
                'taskable_type' => Field::class,
                'taskable_id' => $fields[$i]->id,
                'date' => $gregorianDate,
                'start_time' => date('H:i', $startTime),
                'end_time' => date('H:i', $endTime),
                'created_by' => $this->user->id,
                'status' => 'finished'
            ]);
            $task->gpsMetricsCalculation()->create([
                'tractor_id' => $tractor->id,
                'traveled_distance' => 100 * ($i + 1),
                'work_duration' => 3600 * ($i + 1),
                'stoppage_count' => 5 * ($i + 1),
                'stoppage_duration' => 1200 * ($i + 1),
                'average_speed' => 20 * ($i + 1),
                'efficiency' => 80 - (($i + 1) * 5),
                'date' => $gregorianDate,
            ]);
            $startTime = $endTime;
            $endTime = $startTime + 7200;
        }
        // Create a task with a different operation
        $task = $tractor->tasks()->create([
            'operation_id' => $otherOperation->id,
            'taskable_type' => Field::class,
            'taskable_id' => $fields[2]->id,
            'date' => $gregorianDate,
            'start_time' => date('H:i', $startTime),
            'end_time' => date('H:i', $endTime),
            'created_by' => $this->user->id,
            'status' => 'finished'
        ]);
        $task->gpsMetricsCalculation()->create([
            'tractor_id' => $tractor->id,
            'traveled_distance' => 300,
            'work_duration' => 10800,
            'stoppage_count' => 15,
            'stoppage_duration' => 3600,
            'average_speed' => 60,
            'efficiency' => 70,
            'date' => $gregorianDate,
        ]);

        // Filter reports by tractor and Persian date, without operation filter
        $response = $this->postJson(route('tractor_reports.filter', [
            'tractor_id' => $tractor->id,
            'date' => $persianDate,
        ]));
        $response->assertStatus(200);
        $responseData = $response->json('data');
        $reports = $responseData['reports'];
        // Should return all 3 reports (not filtered by operation)
        $this->assertCount(3, $reports);
        // Check that all operation names are present
        $operationNames = collect($reports)->pluck('task.operation.name');
        $this->assertTrue($operationNames->contains($operation->name));
        $this->assertTrue($operationNames->contains($otherOperation->name));
    }
}
