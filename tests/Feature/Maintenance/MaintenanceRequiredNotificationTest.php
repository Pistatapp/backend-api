<?php

namespace Tests\Feature\Maintenance;

use App\Models\MaintenanceReport;
use App\Models\Tractor;
use App\Notifications\MaintenanceRequiredNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class MaintenanceRequiredNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_payload_contains_expected_keys_and_metadata(): void
    {
        App::setLocale('fa');

        $tractor = Tractor::factory()->create(['name' => 'TestTractor']);
        $report = MaintenanceReport::factory()->create([
            'maintainable_type' => Tractor::class,
            'maintainable_id' => $tractor->id,
            'date' => Carbon::parse('2026-01-01'),
        ]);

        $notification = new MaintenanceRequiredNotification(
            $tractor,
            155.75,
            100.0,
            $report
        );

        $user = \App\Models\User::factory()->create();
        $array = $notification->toArray($user);

        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertSame($tractor->id, $array['tractor_id']);
        $this->assertSame($report->id, $array['maintenance_report_id']);
        $this->assertSame('warning', $array['color']);
        $this->assertStringContainsString('TestTractor', $array['title']);
    }

    public function test_via_returns_database_channel_only(): void
    {
        $tractor = Tractor::factory()->create();
        $report = MaintenanceReport::factory()->create([
            'maintainable_type' => Tractor::class,
            'maintainable_id' => $tractor->id,
        ]);

        $notification = new MaintenanceRequiredNotification($tractor, 10.0, 5.0, $report);
        $user = \App\Models\User::factory()->create();

        $this->assertSame(['database'], $notification->via($user));
    }
}
