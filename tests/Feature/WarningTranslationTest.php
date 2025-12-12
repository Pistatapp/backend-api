<?php

namespace Tests\Feature;

use App\Services\WarningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarningTranslationTest extends TestCase
{
    private WarningService $warningService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warningService = new WarningService();
    }

    public function test_warning_message_translation_english()
    {
        app()->setLocale('en');

        $message = $this->warningService->formatWarningMessage('tractor_stoppage', [
            'tractor_name' => 'Tractor-01',
            'hours' => '5',
            'date' => '2025-12-12'
        ]);

        $this->assertStringContainsString('Tractor name Tractor-01 has been stopped', $message);
        $this->assertStringContainsString('more than 5 hours', $message);
        $this->assertStringContainsString('on 2025-12-12', $message);
    }

    public function test_warning_message_translation_persian()
    {
        app()->setLocale('fa');

        $message = $this->warningService->formatWarningMessage('tractor_stoppage', [
            'tractor_name' => 'تراکتور-01',
            'hours' => '5',
            'date' => '1404/09/22'
        ]);

        $this->assertStringContainsString('تراکتور با نام تراکتور-01', $message);
        $this->assertStringContainsString('بیش از 5 ساعت', $message);
        $this->assertStringContainsString('در تاریخ 1404/09/22', $message);
    }

    public function test_setting_message_translation_english()
    {
        app()->setLocale('en');

        $message = $this->warningService->formatSettingMessage('tractor_stoppage', [
            'hours' => '3'
        ]);

        $this->assertStringContainsString('Warn me if a tractor stops', $message);
        $this->assertStringContainsString('more than 3 hours', $message);
    }

    public function test_setting_message_translation_persian()
    {
        app()->setLocale('fa');

        $message = $this->warningService->formatSettingMessage('tractor_stoppage', [
            'hours' => '3'
        ]);

        $this->assertStringContainsString('تراکتور بیش از 3 ساعت متوقف', $message);
        $this->assertStringContainsString('هشدار بده', $message);
    }

    public function test_fallback_to_json_when_translation_missing()
    {
        app()->setLocale('es'); // Spanish locale doesn't exist

        $message = $this->warningService->formatWarningMessage('tractor_stoppage', [
            'tractor_name' => 'Tractor-01',
            'hours' => '5',
            'date' => '2025-12-12'
        ]);

        // Should fall back to the JSON definition
        $this->assertStringContainsString('Tractor name Tractor-01 has been stopped', $message);
    }

    public function test_has_translation_method()
    {
        $this->assertTrue($this->warningService->hasTranslation('tractor_stoppage', 'warning', 'en'));
        $this->assertTrue($this->warningService->hasTranslation('tractor_stoppage', 'warning', 'fa'));
        $this->assertTrue($this->warningService->hasTranslation('tractor_stoppage', 'setting', 'en'));
        $this->assertTrue($this->warningService->hasTranslation('tractor_stoppage', 'setting', 'fa'));

        $this->assertFalse($this->warningService->hasTranslation('non_existent_warning', 'warning', 'en'));
        $this->assertFalse($this->warningService->hasTranslation('tractor_stoppage', 'warning', 'es'));
    }

    public function test_get_available_locales()
    {
        $locales = $this->warningService->getAvailableLocales();

        $this->assertContains('en', $locales);
        $this->assertContains('fa', $locales);
    }
}
