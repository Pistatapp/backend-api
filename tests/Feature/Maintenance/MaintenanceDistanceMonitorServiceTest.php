<?php

namespace Tests\Feature\Maintenance;

use App\Models\Farm;
use App\Models\GpsMetricsCalculation;
use App\Models\MaintenanceReport;
use App\Models\Tractor;
use App\Models\TractorTask;
use App\Models\User;
use App\Notifications\MaintenanceRequiredNotification;
use App\Services\MaintenanceDistanceMonitorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MaintenanceDistanceMonitorServiceTest extends TestCase
{
    use InteractsWithMaintenanceContext;
    use RefreshDatabase;

    private MaintenanceDistanceMonitorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MaintenanceDistanceMonitorService::class);
    }

    public function test_distance_period_start_matches_report_date_when_no_exit_time(): void
    {
        $report = MaintenanceReport::factory()->make([
            'date' => Carbon::parse('2026-03-15'),
            'repair_shop_exited_at' => null,
        ]);

        $start = $this->service->distancePeriodStart($report);

        $this->assertTrue($start->equalTo(Carbon::parse('2026-03-15')->startOfDay()));
    }

    public function test_distance_period_start_is_day_after_repair_shop_exit(): void
    {
        $report = MaintenanceReport::factory()->make([
            'date' => Carbon::parse('2026-03-15'),
            'repair_shop_exited_at' => Carbon::parse('2026-03-10 14:30:00'),
        ]);

        $start = $this->service->distancePeriodStart($report);

        $this->assertTrue($start->equalTo(Carbon::parse('2026-03-11')->startOfDay()));
    }

    public function test_traveled_distance_sums_only_daily_metrics_rows(): void
    {
        $tractor = Tractor::factory()->create();
        $task = TractorTask::factory()->create(['tractor_id' => $tractor->id]);

        $report = MaintenanceReport::factory()->create([
            'maintainable_type' => Tractor::class,
            'maintainable_id' => $tractor->id,
            'date' => Carbon::parse('2026-01-01'),
            'next_maintenance_km' => 1000,
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => $task->id,
            'date' => '2026-01-10',
            'traveled_distance' => 999.0,
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-01-10',
            'traveled_distance' => 40.0,
        ]);

        $km = $this->service->traveledDistanceKmSinceLastReport($tractor, $report->fresh());

        $this->assertEquals(40.0, $km);
    }

    public function test_traveled_distance_excludes_days_before_period_start(): void
    {
        $tractor = Tractor::factory()->create();

        $report = MaintenanceReport::factory()->create([
            'maintainable_type' => Tractor::class,
            'maintainable_id' => $tractor->id,
            'date' => Carbon::parse('2026-01-05'),
            'repair_shop_exited_at' => null,
            'next_maintenance_km' => 500,
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-01-01',
            'traveled_distance' => 200.0,
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-01-05',
            'traveled_distance' => 30.0,
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-01-06',
            'traveled_distance' => 10.0,
        ]);

        $km = $this->service->traveledDistanceKmSinceLastReport($tractor, $report->fresh());

        $this->assertEquals(40.0, $km);
    }

    public function test_traveled_distance_sums_multiple_eligible_days(): void
    {
        $tractor = Tractor::factory()->create();

        $report = MaintenanceReport::factory()->create([
            'maintainable_type' => Tractor::class,
            'maintainable_id' => $tractor->id,
            'date' => Carbon::parse('2026-01-01'),
            'next_maintenance_km' => 1000,
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-01-02',
            'traveled_distance' => 25.5,
        ]);
        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-01-03',
            'traveled_distance' => 14.5,
        ]);

        $km = $this->service->traveledDistanceKmSinceLastReport($tractor, $report->fresh());

        $this->assertEquals(40.0, $km);
    }

    public function test_check_tractor_does_nothing_without_threshold(): void
    {
        Notification::fake();
        Cache::flush();

        $tractor = Tractor::factory()->create();
        MaintenanceReport::factory()->create([
            'maintainable_type' => Tractor::class,
            'maintainable_id' => $tractor->id,
            'date' => Carbon::parse('2026-01-01'),
            'next_maintenance_km' => null,
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-01-10',
            'traveled_distance' => 500.0,
        ]);

        $this->service->checkTractorAndNotify($tractor->fresh());

        Notification::assertNothingSent();
    }

    public function test_check_tractor_does_nothing_when_threshold_is_zero(): void
    {
        Notification::fake();
        Cache::flush();

        $farm = Farm::factory()->create();
        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);
        $farm->users()->attach(User::factory()->create()->id, ['role' => 'admin', 'is_owner' => false]);

        MaintenanceReport::factory()->create([
            'maintainable_type' => Tractor::class,
            'maintainable_id' => $tractor->id,
            'date' => Carbon::parse('2026-01-01'),
            'next_maintenance_km' => 0,
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-01-10',
            'traveled_distance' => 500.0,
        ]);

        $this->service->checkTractorAndNotify($tractor->fresh());

        Notification::assertNothingSent();
    }

    public function test_check_tractor_does_nothing_when_below_threshold(): void
    {
        Notification::fake();
        Cache::flush();

        $farm = Farm::factory()->create();
        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);
        $farm->users()->attach(User::factory()->create()->id, ['role' => 'admin', 'is_owner' => false]);

        MaintenanceReport::factory()->create([
            'maintainable_type' => Tractor::class,
            'maintainable_id' => $tractor->id,
            'date' => Carbon::parse('2026-01-01'),
            'next_maintenance_km' => 100,
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-01-10',
            'traveled_distance' => 50.0,
        ]);

        $this->service->checkTractorAndNotify($tractor->fresh());

        Notification::assertNothingSent();
    }

    public function test_check_tractor_notifies_all_farm_admins(): void
    {
        Notification::fake();
        Cache::flush();

        $farm = Farm::factory()->create();
        $adminA = User::factory()->create();
        $adminB = User::factory()->create();
        $farm->users()->attach($adminA->id, ['role' => 'admin', 'is_owner' => false]);
        $farm->users()->attach($adminB->id, ['role' => 'admin', 'is_owner' => false]);

        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);

        MaintenanceReport::factory()->create([
            'maintainable_type' => Tractor::class,
            'maintainable_id' => $tractor->id,
            'date' => Carbon::parse('2026-01-01'),
            'next_maintenance_km' => 100,
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-01-10',
            'traveled_distance' => 150.0,
        ]);

        $this->service->checkTractorAndNotify($tractor->fresh());

        Notification::assertSentTo($adminA, MaintenanceRequiredNotification::class);
        Notification::assertSentTo($adminB, MaintenanceRequiredNotification::class);
    }

    public function test_check_tractor_skips_non_admin_farm_users(): void
    {
        Notification::fake();
        Cache::flush();

        $farm = Farm::factory()->create();
        $viewer = User::factory()->create();
        $farm->users()->attach($viewer->id, ['role' => 'viewer', 'is_owner' => false]);

        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);

        MaintenanceReport::factory()->create([
            'maintainable_type' => Tractor::class,
            'maintainable_id' => $tractor->id,
            'date' => Carbon::parse('2026-01-01'),
            'next_maintenance_km' => 100,
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-01-10',
            'traveled_distance' => 150.0,
        ]);

        $this->service->checkTractorAndNotify($tractor->fresh());

        Notification::assertNothingSent();
    }

    public function test_notification_sent_only_once_per_cache_key_until_threshold_changes(): void
    {
        Notification::fake();
        Cache::flush();

        $farm = Farm::factory()->create();
        $admin = User::factory()->create();
        $farm->users()->attach($admin->id, ['role' => 'admin', 'is_owner' => false]);

        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);

        MaintenanceReport::factory()->create([
            'maintainable_type' => Tractor::class,
            'maintainable_id' => $tractor->id,
            'date' => Carbon::parse('2026-01-01'),
            'next_maintenance_km' => 100,
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-01-10',
            'traveled_distance' => 150.0,
        ]);

        $this->service->checkTractorAndNotify($tractor->fresh());
        $this->service->checkTractorAndNotify($tractor->fresh());

        Notification::assertSentToTimes($admin, MaintenanceRequiredNotification::class, 1);
    }

    public function test_uses_latest_report_by_date_then_id(): void
    {
        Notification::fake();
        Cache::flush();

        $farm = Farm::factory()->create();
        $admin = User::factory()->create();
        $farm->users()->attach($admin->id, ['role' => 'admin', 'is_owner' => false]);

        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);

        MaintenanceReport::factory()->create([
            'maintainable_type' => Tractor::class,
            'maintainable_id' => $tractor->id,
            'date' => Carbon::parse('2026-01-01'),
            'next_maintenance_km' => 50,
        ]);

        MaintenanceReport::factory()->create([
            'maintainable_type' => Tractor::class,
            'maintainable_id' => $tractor->id,
            'date' => Carbon::parse('2026-02-01'),
            'next_maintenance_km' => 500,
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-02-05',
            'traveled_distance' => 200.0,
        ]);

        $this->service->checkTractorAndNotify($tractor->fresh());

        Notification::assertNothingSent();
    }

    public function test_notification_fires_when_latest_report_exceeded_but_older_report_would_not(): void
    {
        Notification::fake();
        Cache::flush();

        $farm = Farm::factory()->create();
        $admin = User::factory()->create();
        $farm->users()->attach($admin->id, ['role' => 'admin', 'is_owner' => false]);

        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);

        MaintenanceReport::factory()->create([
            'maintainable_type' => Tractor::class,
            'maintainable_id' => $tractor->id,
            'date' => Carbon::parse('2026-01-01'),
            'next_maintenance_km' => 1000,
        ]);

        MaintenanceReport::factory()->create([
            'maintainable_type' => Tractor::class,
            'maintainable_id' => $tractor->id,
            'date' => Carbon::parse('2026-02-01'),
            'next_maintenance_km' => 80,
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-02-05',
            'traveled_distance' => 100.0,
        ]);

        $this->service->checkTractorAndNotify($tractor->fresh());

        Notification::assertSentTo($admin, MaintenanceRequiredNotification::class);
    }

    public function test_notification_cache_key_changes_when_next_maintenance_km_updates(): void
    {
        Notification::fake();
        Cache::flush();

        $farm = Farm::factory()->create();
        $admin = User::factory()->create();
        $farm->users()->attach($admin->id, ['role' => 'admin', 'is_owner' => false]);

        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);

        $report = MaintenanceReport::factory()->create([
            'maintainable_type' => Tractor::class,
            'maintainable_id' => $tractor->id,
            'date' => Carbon::parse('2026-01-01'),
            'next_maintenance_km' => 100,
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $tractor->id,
            'tractor_task_id' => null,
            'date' => '2026-01-10',
            'traveled_distance' => 150.0,
        ]);

        $this->service->checkTractorAndNotify($tractor->fresh());
        Notification::assertSentToTimes($admin, MaintenanceRequiredNotification::class, 1);

        $report->update(['next_maintenance_km' => 120]);

        $this->service->checkTractorAndNotify($tractor->fresh());

        Notification::assertSentToTimes($admin, MaintenanceRequiredNotification::class, 2);
    }

    public function test_check_all_tractors_processes_tractors_with_threshold_reports(): void
    {
        Notification::fake();
        Cache::flush();

        $farm = Farm::factory()->create();
        $admin = User::factory()->create();
        $farm->users()->attach($admin->id, ['role' => 'admin', 'is_owner' => false]);

        $t1 = Tractor::factory()->create(['farm_id' => $farm->id]);
        $t2 = Tractor::factory()->create(['farm_id' => $farm->id]);

        MaintenanceReport::factory()->create([
            'maintainable_type' => Tractor::class,
            'maintainable_id' => $t1->id,
            'date' => Carbon::parse('2026-01-01'),
            'next_maintenance_km' => 100,
        ]);
        MaintenanceReport::factory()->create([
            'maintainable_type' => Tractor::class,
            'maintainable_id' => $t2->id,
            'date' => Carbon::parse('2026-01-01'),
            'next_maintenance_km' => 100,
        ]);

        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $t1->id,
            'tractor_task_id' => null,
            'date' => '2026-01-15',
            'traveled_distance' => 150.0,
        ]);
        GpsMetricsCalculation::factory()->create([
            'tractor_id' => $t2->id,
            'tractor_task_id' => null,
            'date' => '2026-01-15',
            'traveled_distance' => 150.0,
        ]);

        $this->service->checkAllTractors();

        Notification::assertSentToTimes($admin, MaintenanceRequiredNotification::class, 2);
    }
}
