<?php

namespace Tests\Unit\Services;

use App\Models\Irrigation;
use App\Services\IrrigationLifecycleService;
use Carbon\Carbon;
use Tests\TestCase;

class IrrigationLifecycleServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_operator_confirmation_closes_operator_window_and_starts_admin_window(): void
    {
        $irrigation = new Irrigation([
            'start_time' => '2026-08-01 08:00:00',
            'end_time' => '2026-08-01 10:00:00',
            'status' => 'finished',
        ]);
        $irrigation->operator_confirmed_at = Carbon::parse('2026-08-01 11:00:00', 'Asia/Tehran');
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00', 'Asia/Tehran'));

        $service = app(IrrigationLifecycleService::class);

        $this->assertFalse($service->canOperatorEdit($irrigation));
        $this->assertSame('2026-08-01T11:00:00+03:30', $service->adminWindowStart($irrigation)->toIso8601String());
        $this->assertTrue($service->canAdminEdit($irrigation));
    }

    public function test_admin_window_auto_finalizes_after_seventy_two_hours(): void
    {
        $irrigation = new Irrigation([
            'start_time' => '2026-08-01 08:00:00',
            'end_time' => '2026-08-01 10:00:00',
            'status' => 'finished',
            'operator_confirmed_at' => '2026-08-01 11:00:00',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-08-04 11:00:00', 'Asia/Tehran'));

        $service = app(IrrigationLifecycleService::class);
        $this->assertTrue($service->isFinal($irrigation));
        $this->assertFalse($service->reportEligible($irrigation));
    }

    public function test_legacy_admin_verified_irrigation_remains_report_eligible(): void
    {
        $irrigation = new Irrigation([
            'start_time' => '2026-08-01 08:00:00',
            'end_time' => '2026-08-01 10:00:00',
            'status' => 'finished',
            'is_verified_by_admin' => true,
        ]);

        $this->assertTrue(app(IrrigationLifecycleService::class)->reportEligible($irrigation));
    }
}
