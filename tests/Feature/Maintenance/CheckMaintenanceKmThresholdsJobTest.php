<?php

namespace Tests\Feature\Maintenance;

use App\Jobs\CheckMaintenanceKmThresholdsJob;
use App\Models\Farm;
use App\Models\GpsMetricsCalculation;
use App\Models\MaintenanceReport;
use App\Models\Tractor;
use App\Models\User;
use App\Notifications\MaintenanceRequiredNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CheckMaintenanceKmThresholdsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_runs_maintenance_check_for_tractors_over_threshold(): void
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
            'date' => '2026-01-20',
            'traveled_distance' => 200.0,
        ]);

        (new CheckMaintenanceKmThresholdsJob)->handle(
            app(\App\Services\MaintenanceDistanceMonitorService::class)
        );

        Notification::assertSentTo($admin, MaintenanceRequiredNotification::class);
    }
}
