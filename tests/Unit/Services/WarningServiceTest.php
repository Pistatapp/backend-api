<?php

namespace Tests\Unit\Services;

use App\Services\WarningService;
use Illuminate\Support\Facades\Storage;
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
        $this->assertCount(1, $tractorWarnings);
        $this->assertEquals('tractors', $tractorWarnings['tractor_maintenance']['related-to']);
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
}
