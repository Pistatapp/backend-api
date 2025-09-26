<?php

namespace Tests\Feature;

use App\Services\WarningService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class WarningTranslationTest extends TestCase
{
    private WarningService $warningService;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::put('json/warnings.json', json_encode([
            'tractor_stoppage' => [
                'related-to' => 'tractors',
                'setting-message' => 'Warn me if a tractor stops for more than :hours hours.',
                'setting-message-parameters' => ['hours'],
                'warning-message' => 'Tractor name :tractor_name has been stopped for more than :hours hours on :date. Please check the reason.',
                'warning-message-parameters' => ['tractor_name', 'hours', 'date']
            ],
            'irrigation_start_end' => [
                'related-to' => 'irrigation',
                'setting-message' => 'Warn me at the start and end of irrigation.',
                'setting-message-parameters' => [],
                'warning-message' => 'Irrigation operation started on :start_date at :start_time in plot :plot and ended at :end_time.',
                'warning-message-parameters' => ['start_date', 'start_time', 'plot', 'end_time']
            ],
            'frost_warning' => [
                'related-to' => 'farm',
                'setting-message' => 'Warn me :days days before a potential frost event.',
                'setting-message-parameters' => ['days'],
                'warning-message' => 'There is a risk of frost in your farm in the next :days days. Take precautions.',
                'warning-message-parameters' => ['days']
            ]
        ]));

        $this->warningService = new WarningService();
    }

    #[Test]
    public function it_uses_persian_translations_when_locale_is_persian(): void
    {
        App::setLocale('fa');

        $message = $this->warningService->formatWarningMessage('tractor_stoppage', [
            'tractor_name' => 'Tractor-001',
            'hours' => '5',
            'date' => '2024-01-15'
        ]);

        // Should use Persian translation from warnings.php file
        $this->assertEquals(
            'تراکتور با نام Tractor-001 بیش از 5 ساعت در تاریخ 2024-01-15 متوقف بوده است. لطفا دلیل آن را بررسی کنید.',
            $message
        );
    }

    #[Test]
    public function it_handles_parameter_replacement_correctly(): void
    {
        App::setLocale('en');

        $message = $this->warningService->formatWarningMessage('irrigation_start_end', [
            'start_date' => '2024-01-15',
            'start_time' => '08:00',
            'plot' => 'Plot-A',
            'end_time' => '10:30'
        ]);

        $this->assertEquals(
            'Irrigation operation started on 2024-01-15 at 08:00 in plot Plot-A and ended at 10:30.',
            $message
        );
    }

    #[Test]
    public function it_handles_irrigation_translation_with_parameters(): void
    {
        App::setLocale('fa');

        $message = $this->warningService->formatWarningMessage('irrigation_start_end', [
            'start_date' => '2024-01-15',
            'start_time' => '08:00',
            'plot' => 'Plot-A',
            'end_time' => '10:30'
        ]);

        $this->assertEquals(
            'عملیات آبیاری در تاریخ 2024-01-15 ساعت 08:00 در قطعه Plot-A شروع شد و در ساعت 10:30 به پایان رسید.',
            $message
        );
    }

    #[Test]
    public function it_handles_frost_warning_with_parameters(): void
    {
        App::setLocale('en');

        $message = $this->warningService->formatWarningMessage('frost_warning', [
            'days' => '3'
        ]);

        $this->assertEquals(
            'There is a risk of frost in your farm in the next 3 days. Take precautions.',
            $message
        );
    }

    #[Test]
    public function it_falls_back_to_english_when_translation_missing(): void
    {
        App::setLocale('fa');

        // Create a warning that doesn't exist in translations
        Storage::put('json/warnings.json', json_encode([
            'unknown_warning' => [
                'related-to' => 'test',
                'setting-message' => 'Test warning.',
                'setting-message-parameters' => [],
                'warning-message' => 'This is a test warning message.',
                'warning-message-parameters' => []
            ]
        ]));

        $this->warningService = new WarningService();

        $message = $this->warningService->formatWarningMessage('unknown_warning', []);

        $this->assertEquals(
            'This is a test warning message.',
            $message
        );
    }

    #[Test]
    public function it_handles_partial_parameters(): void
    {
        App::setLocale('en');

        $message = $this->warningService->formatWarningMessage('tractor_stoppage', [
            'tractor_name' => 'Tractor-001',
            'hours' => '3'
            // Missing 'date' parameter
        ]);

        $this->assertEquals(
            'Tractor name Tractor-001 has been stopped for more than 3 hours on :date. Please check the reason.',
            $message
        );
    }

    #[Test]
    public function it_handles_empty_parameters(): void
    {
        App::setLocale('en');

        $message = $this->warningService->formatWarningMessage('irrigation_start_end', []);

        $this->assertEquals(
            'Irrigation operation started on :start_date at :start_time in plot :plot and ended at :end_time.',
            $message
        );
    }

    #[Test]
    public function it_verifies_translation_keys_exist_in_warnings_file(): void
    {
        // This test verifies that the translation keys we expect exist in the warnings.php file
        $warningsPath = base_path('lang/fa/warnings.php');

        if (file_exists($warningsPath)) {
            $warningsTranslations = include $warningsPath;

            $expectedWarningKeys = [
                'tractor_stoppage',
                'tractor_inactivity',
                'irrigation_start_end',
                'frost_warning',
                'radiative_frost_warning',
                'oil_spray_warning',
                'pest_degree_day_warning',
                'crop_type_degree_day_warning'
            ];

            foreach ($expectedWarningKeys as $key) {
                $this->assertArrayHasKey($key, $warningsTranslations,
                    "Translation key '{$key}' should exist in warnings.php file");
            }
        } else {
            $this->markTestSkipped('warnings.php file not found - this is expected in test environment');
        }
    }
}
