<?php

namespace Tests\Unit\Services;

use App\Services\WarningService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\App;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class WarningServiceTest extends TestCase
{
    private WarningService $warningService;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::put('json/warnings.json', json_encode([
            'frost_warning' => [
                'related-to' => 'garden',
                'setting-message' => 'Warn me :days days before a potential frost event.',
                'setting-message-parameters' => ['days'],
                'warning-message' => 'There is a risk of frost in your garden in the next :days days. Take precautions.',
                'warning-message-parameters' => ['days']
            ],
            'tractor_maintenance' => [
                'related-to' => 'tractors',
                'setting-message' => 'Warn me when tractor needs maintenance after :hours hours.',
                'setting-message-parameters' => ['hours'],
                'warning-message' => 'Tractor needs maintenance after :hours hours of operation.',
                'warning-message-parameters' => ['hours']
            ],
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
            ]
        ]));


        $this->warningService = new WarningService();
    }

    #[Test]
    public function it_gets_warnings_by_related_to(): void
    {
        $gardenWarnings = $this->warningService->getWarningsByRelatedTo('garden');
        $this->assertCount(1, $gardenWarnings);
        $this->assertEquals('garden', $gardenWarnings['frost_warning']['related-to']);

        $tractorWarnings = $this->warningService->getWarningsByRelatedTo('tractors');
        $this->assertCount(2, $tractorWarnings);
        $this->assertEquals('tractors', $tractorWarnings['tractor_maintenance']['related-to']);
        $this->assertEquals('tractors', $tractorWarnings['tractor_stoppage']['related-to']);
    }

    #[Test]
    public function it_validates_parameters(): void
    {
        // Valid parameters
        $this->assertTrue(
            $this->warningService->validateParameters('frost_warning', ['days' => '3'])
        );

        // Invalid parameter name
        $this->assertFalse(
            $this->warningService->validateParameters('frost_warning', ['invalid' => '3'])
        );

        // Missing required parameter
        $this->assertFalse(
            $this->warningService->validateParameters('frost_warning', [])
        );

        // Invalid warning key
        $this->assertFalse(
            $this->warningService->validateParameters('invalid_warning', ['days' => '3'])
        );
    }

    #[Test]
    public function it_formats_warning_message(): void
    {
        $message = $this->warningService->formatWarningMessage('frost_warning', ['days' => '3']);
        $this->assertEquals(
            'There is a risk of frost in your garden in the next 3 days. Take precautions.',
            $message
        );

        // Invalid warning key returns empty string
        $this->assertEquals(
            '',
            $this->warningService->formatWarningMessage('invalid_warning', ['days' => '3'])
        );
    }

    #[Test]
    public function it_formats_setting_message(): void
    {
        $message = $this->warningService->formatSettingMessage('frost_warning', ['days' => '3']);
        $this->assertEquals(
            'Warn me 3 days before a potential frost event.',
            $message
        );

        // Invalid warning key returns empty string
        $this->assertEquals(
            '',
            $this->warningService->formatSettingMessage('invalid_warning', ['days' => '3'])
        );
    }

    #[Test]
    public function it_uses_english_message_when_locale_is_english(): void
    {
        App::setLocale('en');

        $message = $this->warningService->formatWarningMessage('tractor_stoppage', [
            'tractor_name' => 'Tractor-001',
            'hours' => '5',
            'date' => '2024-01-15'
        ]);

        $this->assertEquals(
            'Tractor name Tractor-001 has been stopped for more than 5 hours on 2024-01-15. Please check the reason.',
            $message
        );
    }

    #[Test]
    public function it_falls_back_to_english_when_translation_missing(): void
    {
        App::setLocale('fa');

        // Test with a warning that doesn't have translation
        $message = $this->warningService->formatWarningMessage('tractor_maintenance', [
            'hours' => '100'
        ]);

        $this->assertEquals(
            'Tractor needs maintenance after 100 hours of operation.',
            $message
        );
    }

    #[Test]
    public function it_works_with_different_locales(): void
    {
        // Test with Spanish locale (should fall back to English)
        App::setLocale('es');

        $message = $this->warningService->formatWarningMessage('tractor_stoppage', [
            'tractor_name' => 'Tractor-001',
            'hours' => '2',
            'date' => '2024-01-15'
        ]);

        $this->assertEquals(
            'Tractor name Tractor-001 has been stopped for more than 2 hours on 2024-01-15. Please check the reason.',
            $message
        );
    }

    #[Test]
    public function it_handles_empty_parameters_correctly(): void
    {
        App::setLocale('en');

        $message = $this->warningService->formatWarningMessage('irrigation_start_end', []);

        $this->assertEquals(
            'Irrigation operation started on :start_date at :start_time in plot :plot and ended at :end_time.',
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
    public function it_handles_complex_parameter_replacement(): void
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
}
