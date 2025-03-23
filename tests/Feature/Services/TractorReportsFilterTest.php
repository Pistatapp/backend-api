<?php

namespace Tests\Feature\Services;

use App\Models\Operation;
use App\Models\Tractor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TractorReportsFilterTest extends TestCase
{
    use RefreshDatabase;

    private $user;
    private $tractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /** @test */
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
                'field_id' => $fields[$i]->id,
                'date' => $gregorianDate, // Store in Gregorian format in database
                'start_time' => date('H:i', $startTime),
                'end_time' => date('H:i', $endTime),
                'created_by' => $this->user->id,
                'status' => 'finished'
            ]);

            $task->gpsDailyReport()->create([
                'tractor_id' => $tractor->id,
                'traveled_distance' => 100 * ($i + 1),
                'work_duration' => 3600 * ($i + 1),
                'stoppage_count' => 5 * ($i + 1),
                'stoppage_duration' => 1200 * ($i + 1),
                'average_speed' => 20 * ($i + 1),
                'max_speed' => 50 * ($i + 1),
                'efficiency' => 80 - (($i + 1) * 5),
                'date' => $gregorianDate,
            ]);

            $startTime = $endTime;
            $endTime = $startTime + 7200; // 2 hours interval
        }

        // Filter reports by tractor and Persian date
        $response = $this->postJson(route('tractor.reports.filter', [
            'tractor_id' => $tractor->id,
            'date' => $persianDate,
        ]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'reports' => [
                    '*' => [
                        'operation_name',
                        'filed_name',
                        'traveled_distance',
                        'min_speed',
                        'max_speed',
                        'avg_speed',
                        'work_duration',
                        'stoppage_duration',
                        'stoppage_count',
                    ],
                ],
                'accumulated' => [
                    'traveled_distance',
                    'min_speed',
                    'max_speed',
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
        $this->assertEquals($operations[0]->name, $reports[0]['operation_name']);
        $this->assertEquals($fields[0]->name, $reports[0]['filed_name']);
        $this->assertEquals(100, $reports[0]['traveled_distance']);
        $this->assertEquals(20, $reports[0]['avg_speed']);
        $this->assertEquals(50, $reports[0]['max_speed']);
        $this->assertEquals(3600, $reports[0]['work_duration']);
        $this->assertEquals(1200, $reports[0]['stoppage_duration']);
        $this->assertEquals(5, $reports[0]['stoppage_count']);

        // Validate second report values
        $this->assertEquals($operations[1]->name, $reports[1]['operation_name']);
        $this->assertEquals($fields[1]->name, $reports[1]['filed_name']);
        $this->assertEquals(200, $reports[1]['traveled_distance']);
        $this->assertEquals(40, $reports[1]['avg_speed']);
        $this->assertEquals(100, $reports[1]['max_speed']);
        $this->assertEquals(7200, $reports[1]['work_duration']);
        $this->assertEquals(2400, $reports[1]['stoppage_duration']);
        $this->assertEquals(10, $reports[1]['stoppage_count']);

        // Validate third report values
        $this->assertEquals($operations[2]->name, $reports[2]['operation_name']);
        $this->assertEquals($fields[2]->name, $reports[2]['filed_name']);
        $this->assertEquals(300, $reports[2]['traveled_distance']);
        $this->assertEquals(60, $reports[2]['avg_speed']);
        $this->assertEquals(150, $reports[2]['max_speed']);
        $this->assertEquals(10800, $reports[2]['work_duration']);
        $this->assertEquals(3600, $reports[2]['stoppage_duration']);
        $this->assertEquals(15, $reports[2]['stoppage_count']);

        // Validate accumulated values
        $accumulated = $responseData['accumulated'];
        $this->assertEquals(600, $accumulated['traveled_distance']); // 100 + 200 + 300
        $this->assertEquals(0, $accumulated['min_speed']); // Minimum speed
        $this->assertEquals(150, $accumulated['max_speed']); // Maximum speed across all reports
        $this->assertEquals(40, $accumulated['avg_speed']); // Average of all average speeds
        $this->assertEquals(21600, $accumulated['work_duration']); // Sum of all work durations
        $this->assertEquals(7200, $accumulated['stoppage_duration']); // Sum of all stoppage durations
        $this->assertEquals(30, $accumulated['stoppage_count']); // Sum of all stoppage counts

        // Validate expectations
        $expectations = $responseData['expectations'];
        $this->assertEquals(28800, $expectations['expected_daily_work']); // 8 hours in seconds
        $this->assertEquals(21600, $expectations['total_work_duration']); // Sum of all work durations
        $this->assertEquals(75, $expectations['total_efficiency']); // (21600 / 28800) * 100
    }

    /** @test */
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
                'field_id' => $fields[$i]->id,
                'date' => $gregorianDate, // Store in Gregorian format in database
                'start_time' => date('H:i', $startTime),
                'end_time' => date('H:i', $endTime),
                'created_by' => $this->user->id,
                'status' => 'finished'
            ]);

            $task->gpsDailyReport()->create([
                'tractor_id' => $tractor->id,
                'traveled_distance' => 100 * ($i + 1),
                'work_duration' => 3600 * ($i + 1),
                'stoppage_count' => 5 * ($i + 1),
                'stoppage_duration' => 1200 * ($i + 1),
                'average_speed' => 20 * ($i + 1),
                'max_speed' => 50 * ($i + 1),
                'efficiency' => 80 - (($i + 1) * 5),
                'date' => $gregorianDate,
            ]);

            $startTime = $endTime;
            $endTime = $startTime + 7200;
        }

        // Create a task with different operation (should not be included in results)
        $task = $tractor->tasks()->create([
            'operation_id' => $otherOperation->id,
            'field_id' => $fields[2]->id,
            'date' => $gregorianDate,
            'start_time' => date('H:i', $startTime),
            'end_time' => date('H:i', $endTime),
            'created_by' => $this->user->id,
            'status' => 'finished'
        ]);

        $task->gpsDailyReport()->create([
            'tractor_id' => $tractor->id,
            'traveled_distance' => 300,
            'work_duration' => 10800,
            'stoppage_count' => 15,
            'stoppage_duration' => 3600,
            'average_speed' => 60,
            'max_speed' => 150,
            'efficiency' => 70,
            'date' => $gregorianDate,
        ]);

        // Filter reports by tractor, operation and Persian date
        $response = $this->postJson(route('tractor.reports.filter', [
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
        $this->assertEquals($operation->name, $reports[0]['operation_name']);
        $this->assertEquals($fields[0]->name, $reports[0]['filed_name']);
        $this->assertEquals(100, $reports[0]['traveled_distance']);
        $this->assertEquals(20, $reports[0]['avg_speed']);
        $this->assertEquals(50, $reports[0]['max_speed']);
        $this->assertEquals(3600, $reports[0]['work_duration']);
        $this->assertEquals(1200, $reports[0]['stoppage_duration']);
        $this->assertEquals(5, $reports[0]['stoppage_count']);

        // Validate second report values
        $this->assertEquals($operation->name, $reports[1]['operation_name']);
        $this->assertEquals($fields[1]->name, $reports[1]['filed_name']);
        $this->assertEquals(200, $reports[1]['traveled_distance']);
        $this->assertEquals(40, $reports[1]['avg_speed']);
        $this->assertEquals(100, $reports[1]['max_speed']);
        $this->assertEquals(7200, $reports[1]['work_duration']);
        $this->assertEquals(2400, $reports[1]['stoppage_duration']);
        $this->assertEquals(10, $reports[1]['stoppage_count']);

        // Validate accumulated values (only for the filtered operation)
        $accumulated = $responseData['accumulated'];
        $this->assertEquals(300, $accumulated['traveled_distance']); // 100 + 200
        $this->assertEquals(0, $accumulated['min_speed']);
        $this->assertEquals(100, $accumulated['max_speed']); // Max from the filtered reports
        $this->assertEquals(30, $accumulated['avg_speed']); // Average of 20 and 40
        $this->assertEquals(10800, $accumulated['work_duration']); // 3600 + 7200
        $this->assertEquals(3600, $accumulated['stoppage_duration']); // 1200 + 2400
        $this->assertEquals(15, $accumulated['stoppage_count']); // 5 + 10

        // Validate expectations
        $expectations = $responseData['expectations'];
        $this->assertEquals(28800, $expectations['expected_daily_work']); // 8 hours in seconds
        $this->assertEquals(10800, $expectations['total_work_duration']); // Sum of filtered work durations
        $this->assertEquals(37.5, $expectations['total_efficiency']); // (10800 / 28800) * 100
    }

    /** @test */
    public function it_filters_reports_by_tractor_and_current_month(): void
    {
        $tractor = Tractor::factory()->create();

        // Create tasks for current month
        $currentMonthTasks = collect([
            ['date' => now(), 'efficiency' => 75],
            ['date' => now()->subDays(5), 'efficiency' => 80],
            ['date' => now()->subDays(10), 'efficiency' => 85],
        ]);

        // Create task for last month (should not be included)
        $lastMonthTask = ['date' => now()->subMonth(), 'efficiency' => 90];

        // Create all tasks
        $operation = Operation::factory()->create();
        $field = \App\Models\Field::factory()->create(['farm_id' => $tractor->farm_id]);

        // Helper function to create task and its report
        $createTaskWithReport = function ($taskData) use ($tractor, $operation, $field) {
            $task = $tractor->tasks()->create([
                'operation_id' => $operation->id,
                'field_id' => $field->id,
                'date' => $taskData['date']->format('Y-m-d'),
                'start_time' => '08:00',
                'end_time' => '16:00',
                'created_by' => $this->user->id,
                'status' => 'finished'
            ]);

            $task->gpsDailyReport()->create([
                'tractor_id' => $tractor->id,
                'traveled_distance' => 100,
                'work_duration' => 28800, // 8 hours
                'stoppage_count' => 5,
                'stoppage_duration' => 1800,
                'average_speed' => 20,
                'max_speed' => 40,
                'efficiency' => $taskData['efficiency'],
                'date' => $taskData['date']->format('Y-m-d'),
            ]);
        };

        // Create tasks
        $currentMonthTasks->each(function ($taskData) use ($createTaskWithReport) {
            $createTaskWithReport($taskData);
        });
        $createTaskWithReport($lastMonthTask);

        // Test filtering by current month
        $response = $this->postJson(route('tractor.reports.filter', [
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
        $this->assertEquals(300, $accumulated['traveled_distance']); // 3 tasks * 100
        $this->assertEquals(86400, $accumulated['work_duration']); // 3 tasks * 28800 seconds
        $this->assertEquals(15, $accumulated['stoppage_count']); // 3 tasks * 5
        $this->assertEquals(5400, $accumulated['stoppage_duration']); // 3 tasks * 1800
    }

    /** @test */
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
                'field_id' => $field->id,
                'date' => $taskData['date']->format('Y-m-d'),
                'start_time' => '08:00',
                'end_time' => '16:00',
                'created_by' => $this->user->id,
                'status' => 'finished'
            ]);

            $task->gpsDailyReport()->create([
                'tractor_id' => $tractor->id,
                'traveled_distance' => 100,
                'work_duration' => 28800, // 8 hours
                'stoppage_count' => 5,
                'stoppage_duration' => 1800,
                'average_speed' => 20,
                'max_speed' => 40,
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
        $response = $this->postJson(route('tractor.reports.filter', [
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
        $this->assertEquals(300, $accumulated['traveled_distance']); // 3 tasks * 100
        $this->assertEquals(86400, $accumulated['work_duration']); // 3 tasks * 28800 seconds
        $this->assertEquals(15, $accumulated['stoppage_count']); // 3 tasks * 5
        $this->assertEquals(5400, $accumulated['stoppage_duration']); // 3 tasks * 1800
    }

    /** @test */
    public function it_filters_reports_by_tractor_and_specific_month(): void
    {
        $tractor = Tractor::factory()->create();

        // Create tasks for specific month (Farvardin 1404)
        $currentMonthTasks = collect([
            ['date' => '2025-03-21', 'efficiency' => 80], // 1 Farvardin 1404
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
                'field_id' => $field->id,
                'date' => $taskData['date'],
                'start_time' => '08:00',
                'end_time' => '16:00',
                'created_by' => $this->user->id,
                'status' => 'finished'
            ]);

            $task->gpsDailyReport()->create([
                'tractor_id' => $tractor->id,
                'traveled_distance' => 100,
                'work_duration' => 28800, // 8 hours
                'stoppage_count' => 5,
                'stoppage_duration' => 1800,
                'average_speed' => 20,
                'max_speed' => 40,
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
        $response = $this->postJson(route('tractor.reports.filter', [
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
        $this->assertEquals(200, $accumulated['traveled_distance']); // 2 tasks * 100
        $this->assertEquals(57600, $accumulated['work_duration']); // 2 tasks * 28800 seconds
        $this->assertEquals(10, $accumulated['stoppage_count']); // 2 tasks * 5
        $this->assertEquals(3600, $accumulated['stoppage_duration']); // 2 tasks * 1800
    }

    /** @test */
    public function it_filters_reports_by_tractor_and_persian_year(): void
    {
        $tractor = Tractor::factory()->create();

        // Create tasks for Persian year 1404
        $currentYearTasks = collect([
            ['date' => '2025-03-21', 'efficiency' => 80], // 1 Farvardin 1404
            ['date' => '2025-06-22', 'efficiency' => 75], // 1 Tir 1404
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
                'field_id' => $field->id,
                'date' => $taskData['date'],
                'start_time' => '08:00',
                'end_time' => '16:00',
                'created_by' => $this->user->id,
                'status' => 'finished'
            ]);

            $task->gpsDailyReport()->create([
                'tractor_id' => $tractor->id,
                'traveled_distance' => 100,
                'work_duration' => 28800, // 8 hours
                'stoppage_count' => 5,
                'stoppage_duration' => 1800,
                'average_speed' => 20,
                'max_speed' => 40,
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
        $response = $this->postJson(route('tractor.reports.filter', [
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
        $this->assertEquals(300, $accumulated['traveled_distance']); // 3 tasks * 100
        $this->assertEquals(86400, $accumulated['work_duration']); // 3 tasks * 28800 seconds
        $this->assertEquals(15, $accumulated['stoppage_count']); // 3 tasks * 5
        $this->assertEquals(5400, $accumulated['stoppage_duration']); // 3 tasks * 1800
    }

    /** @test */
    public function it_filters_reports_by_specific_persian_year(): void
    {
        $tractor = Tractor::factory()->create();

        // Create tasks for Persian year 1404
        $year1404Tasks = collect([
            ['date' => '2025-03-21', 'efficiency' => 80], // 1 Farvardin 1404
            ['date' => '2025-06-22', 'efficiency' => 75], // 1 Tir 1404
            ['date' => '2026-03-20', 'efficiency' => 85], // 29 Esfand 1404
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
                'field_id' => $field->id,
                'date' => $taskData['date'],
                'start_time' => '08:00',
                'end_time' => '16:00',
                'created_by' => $this->user->id,
                'status' => 'finished'
            ]);

            $task->gpsDailyReport()->create([
                'tractor_id' => $tractor->id,
                'traveled_distance' => 100,
                'work_duration' => 28800, // 8 hours
                'stoppage_count' => 5,
                'stoppage_duration' => 1800,
                'average_speed' => 20,
                'max_speed' => 40,
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
        $response = $this->postJson(route('tractor.reports.filter', [
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
        $this->assertEquals(300, $accumulated['traveled_distance']); // 3 tasks * 100
        $this->assertEquals(86400, $accumulated['work_duration']); // 3 tasks * 28800 seconds
        $this->assertEquals(15, $accumulated['stoppage_count']); // 3 tasks * 5
        $this->assertEquals(5400, $accumulated['stoppage_duration']); // 3 tasks * 1800

        // Verify expectations
        $expectations = $responseData['expectations'];
        $this->assertEquals(28800, $expectations['expected_daily_work']); // 8 hours in seconds
        $this->assertEquals(86400, $expectations['total_work_duration']); // Sum of filtered work durations

        // Calculate efficiency based on actual working days in the period
        $expectedEfficiency = (86400 / (28800 * 3)) * 100; // 3 working days in the test data
        $this->assertEquals($expectedEfficiency, $expectations['total_efficiency']);
    }

    /** @test */
    public function it_calculates_different_efficiencies_based_on_period(): void
    {
        $tractor = Tractor::factory()->create();
        $operation = Operation::factory()->create();
        $field = \App\Models\Field::factory()->create(['farm_id' => $tractor->farm_id]);

        // Create a task with 8 hours of work (100% daily efficiency)
        $createTask = function ($date) use ($tractor, $operation, $field) {
            $task = $tractor->tasks()->create([
                'operation_id' => $operation->id,
                'field_id' => $field->id,
                'date' => $date,
                'start_time' => '08:00',
                'end_time' => '16:00',
                'created_by' => $this->user->id,
                'status' => 'finished'
            ]);

            $task->gpsDailyReport()->create([
                'tractor_id' => $tractor->id,
                'traveled_distance' => 100,
                'work_duration' => 28800, // 8 hours
                'stoppage_count' => 5,
                'stoppage_duration' => 1800,
                'average_speed' => 20,
                'max_speed' => 40,
                'efficiency' => 100,
                'date' => $date,
            ]);
        };

        // Create tasks for testing different periods
        $createTask('2025-03-21'); // 1 Farvardin 1404
        $createTask('2025-03-22'); // 2 Farvardin 1404

        // Test daily efficiency (should be 100% for the specific day)
        $response = $this->postJson(route('tractor.reports.filter', [
            'tractor_id' => $tractor->id,
            'date' => '1404/01/01'
        ]));
        $this->assertEquals(100, $response->json('data.expectations.total_efficiency'));

        // Test monthly efficiency (should be lower since we only worked 2 days)
        $response = $this->postJson(route('tractor.reports.filter', [
            'tractor_id' => $tractor->id,
            'period' => 'specific_month',
            'month' => '1404/01/01'
        ]));
        // Using factory's expected_monthly_work_time (240 hours = 864000 seconds)
        $expectedMonthlyEfficiency = (57600 / (240 * 3600)) * 100;
        $this->assertEquals(round($expectedMonthlyEfficiency, 1), round($response->json('data.expectations.total_efficiency'), 1));

        // Test yearly efficiency (should be even lower)
        $response = $this->postJson(route('tractor.reports.filter', [
            'tractor_id' => $tractor->id,
            'period' => 'persian_year',
            'year' => '1404',
        ]));

        // Using factory's expected_yearly_work_time (2920 hours = 10512000 seconds)
        $expectedYearlyEfficiency = (57600 / (2920 * 3600)) * 100;
        $this->assertEquals(round($expectedYearlyEfficiency, 1), round($response->json('data.expectations.total_efficiency'), 1));
    }
}
