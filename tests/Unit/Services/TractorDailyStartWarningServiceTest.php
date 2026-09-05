<?php

namespace Tests\Unit\Services;

use App\Models\Tractor;
use App\Notifications\TractorNotStartedTodayNotification;
use App\Services\TractorDailyStartWarningService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class TractorDailyStartWarningServiceTest extends TestCase
{
    public function test_only_tractors_without_a_log_are_selected(): void
    {
        $service = new TractorDailyStartWarningService();
        $started = new Tractor(['name' => 'started']);
        $notStarted = new Tractor(['name' => 'not-started']);

        $result = $service->findMissingGpsLogs(
            new Collection([$started, $notStarted]),
            fn (Tractor $tractor): bool => $tractor->name === 'started'
        );

        $this->assertSame(['not-started'], $result->pluck('name')->all());
    }

    public function test_check_is_due_only_at_or_after_the_tehran_cutoff(): void
    {
        $service = new TractorDailyStartWarningService();
        $beforeCutoff = Carbon::parse('2026-09-05 08:59:59', 'Asia/Tehran');
        $atCutoff = Carbon::parse('2026-09-05 09:00:00', 'Asia/Tehran');

        $this->assertFalse($service->isDue($beforeCutoff));
        $this->assertTrue($service->isDue($atCutoff));
    }

    public function test_notification_uses_the_configured_lookup_template(): void
    {
        config()->set('kavenegar.tractor_not_started_template', 'test-tractor-not-started');
        $notification = new TractorNotStartedTodayNotification(new Tractor(['name' => 'تراکتور ۵']));
        $message = $notification->toKavenegar((object) ['mobile' => '09120000000']);

        $this->assertSame('test-tractor-not-started', $message->method);
        $this->assertSame(['تراکتور ۵'], $message->tokens);
    }
}
