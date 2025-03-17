<?php

namespace Tests\Feature\Services;

use Tests\TestCase;
use App\Services\ParseDataService;
use Carbon\Carbon;

class ParseDataServiceTest extends TestCase
{
    private ParseDataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ParseDataService();
        Carbon::setTestNow('2024-01-24 07:02:00'); // Match the time from sample data
    }

    /** @test */
    public function it_correctly_parses_valid_gps_data()
    {
        $jsonData = json_encode([
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240124,070200,000,000,1,863070043386100']
        ]);

        $result = $this->service->parse($jsonData);

        $this->assertCount(1, $result);
        $report = $result[0];

        $this->assertEqualsWithDelta(34.884065, $report['latitude'], 0.000001);
        $this->assertEqualsWithDelta(50.599625, $report['longitude'], 0.000001);
        $this->assertEquals(0, $report['speed']);
        $this->assertEquals(1, $report['status']);
        $this->assertEquals('863070043386100', $report['imei']);
        $this->assertTrue($report['is_stopped']);
        $this->assertEquals(0, $report['stoppage_time']);
        $this->assertFalse($report['is_starting_point']);
        $this->assertFalse($report['is_ending_point']);
    }

    /** @test */
    public function it_filters_out_invalid_format_data()
    {
        $jsonData = json_encode([
            ['data' => 'invalid-format-data'],
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240124,070200,018,000,1,863070043386100']
        ]);

        $result = $this->service->parse($jsonData);

        $this->assertCount(1, $result);
    }

    /** @test */
    public function it_filters_out_non_today_data()
    {
        $jsonData = json_encode([
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,230124,070200,018,000,1,863070043386100'], // Yesterday
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240124,070200,018,000,1,863070043386100']  // Today
        ]);

        $result = $this->service->parse($jsonData);

        $this->assertCount(1, $result);
    }
}
