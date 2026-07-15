<?php

namespace Tests\Feature;

use App\Jobs\IngestGpsData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GpsIngestDelegationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gps_reports.rate_limit_exempt_ips' => ['127.0.0.1'],
            'services.gps_ingest.driver' => 'go',
            'services.gps_ingest.go_url' => 'http://gps-ingest.test',
        ]);
    }

    private function samplePayload(): array
    {
        return [
            'data' => [
                [
                    'coordinate' => [35.937893, 50.065403],
                    'speed' => 0,
                    'status' => 0,
                    'directions' => ['ew' => 3, 'ns' => 1],
                    'date_time' => '2026-02-25 18:49:45',
                    'imei' => '863070046120282',
                ],
            ],
        ];
    }

    public function test_gps_reports_delegates_to_go_service_when_driver_is_go(): void
    {
        Queue::fake();

        Http::fake([
            'http://gps-ingest.test/api/gps/reports' => Http::response(['success' => true], 200),
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/gps/reports', $this->samplePayload());

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://gps-ingest.test/api/gps/reports'
                && $request->hasHeader('X-Real-IP', '127.0.0.1');
        });

        Queue::assertNotPushed(IngestGpsData::class);
    }
}
