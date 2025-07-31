<?php

namespace Tests\Feature\Services;

use Tests\TestCase;
use App\Services\ParseDataService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;

class ParseDataServiceTest extends TestCase
{
    private ParseDataService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ParseDataService();
        Carbon::setTestNow('2024-04-21 07:02:00');
    }

    #[Test]
    public function it_parses_valid_gps_data()
    {
        $data = json_encode([
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240421,070200,018,000,1,1,3,863070043386100']
        ]);

        $result = $this->service->parse($data);

        $this->assertCount(1, $result);
        $this->assertEquals([
            'coordinate' => [
                34.884065,
                50.599625
            ],
            'speed' => 18,
            'status' => 1,
            'ew_direction' => 1,
            'ns_direction' => 3,
            'imei' => '863070043386100',
            'date_time' => Carbon::createFromFormat('ymdHis', '240421070200')->addHours(3)->addMinutes(30),
        ], $result[0]);
    }

    #[Test]
    public function it_filters_out_invalid_format()
    {
        $data = json_encode([
            ['data' => 'invalid format'],
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240421,070200,018,000,1,1,3,863070043386100']
        ]);

        $result = $this->service->parse($data);

        $this->assertCount(1, $result);
        $this->assertEquals('863070043386100', $result[0]['imei']);
    }

    #[Test]
    public function it_filters_out_old_dates()
    {
        Carbon::setTestNow('2024-04-21 07:02:00');

        $data = json_encode([
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240321,070200,018,000,1,1,3,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240421,070200,018,000,1,2,4,863070043386100']
        ]);

        $result = $this->service->parse($data);

        $this->assertCount(1, $result);
        $this->assertEquals('210424 103200', $result[0]['date_time']->format('dmy His'));
    }

    #[Test]
    public function it_sorts_results_by_datetime()
    {
        $data = json_encode([
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240421,070200,018,000,1,1,3,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.04393,05035.9775,000,240421,070100,018,000,1,2,4,863070043386100']
        ]);

        $result = $this->service->parse($data);

        $this->assertCount(2, $result);
        $this->assertEquals('103100', $result[0]['date_time']->format('His'));
        $this->assertEquals('103200', $result[1]['date_time']->format('His'));
    }

    #[Test]
    public function it_correctly_parses_and_sorts_multiple_gps_data()
    {
        // Simulate GPS device data: no commas between JSON objects
        $data = '[
            {"data":"+Hooshnic:V1.03,3453.04393,05035.9775,000,240421,070900,000,000,1,1,3,863070043386100"},
            {"data":"+Hooshnic:V1.03,3453.04393,05035.9775,000,240421,070800,000,000,1,1,3,863070043386100"},
            {"data":"+Hooshnic:V1.03,3453.04393,05035.9775,000,240421,070700,000,000,1,1,3,863070043386100"},
            {"data":"+Hooshnic:V1.03,3453.04393,05035.9775,000,240421,070600,000,000,1,1,3,863070043386100"},
            {"data":"+Hooshnic:V1.03,3453.04393,05035.9775,000,240421,070500,018,000,1,1,3,863070043386100"},
            {"data":"+Hooshnic:V1.03,3453.04393,05035.9775,000,240421,070400,005,000,1,1,3,863070043386100"},
            {"data":"+Hooshnic:V1.03,3453.04393,05035.9775,000,240421,070300,015,000,1,1,3,863070043386100"}
            {"data":"+Hooshnic:V1.03,3453.04393,05035.9775,000,240421,070200,000,000,1,1,3,863070043386100"}
            {"data":"+Hooshnic:V1.03,3453.04393,05035.9775,000,240421,070100,000,000,0,1,3,863070043386100"}
            {"data":"+Hooshnic:V1.03,3453.04393,05035.9775,000,240421,070000,000,000,0,1,3,863070043386100"}
        ]';

        // Test correctJsonFormat directly using Reflection
        $reflection = new \ReflectionClass(\App\Services\ParseDataService::class);
        $method = $reflection->getMethod('correctJsonFormat');
        $method->setAccessible(true);
        $fixed = $method->invoke($this->service, $data);

        $this->assertStringContainsString('},{', $fixed, 'correctJsonFormat should add commas between objects');
        $decoded = json_decode($fixed, true);
        $this->assertIsArray($decoded);
        $this->assertCount(10, $decoded);

        // Now test the main parse method (should work with unseparated string)
        $result = $this->service->parse($data);

        // Verify all data is parsed correctly
        $this->assertCount(10, $result);

        // Verify data is sorted by date_time (ascending)
        $this->assertEquals('103000', $result[0]['date_time']->format('His'));
        $this->assertEquals('103900', $result[9]['date_time']->format('His'));

        // Verify specific entries
        // First entry (earliest time)
        $this->assertEquals([
            'coordinate' => [34.884065, 50.599625],
            'speed' => 0,
            'status' => 0,
            'ew_direction' => 1,
            'ns_direction' => 3,
            'imei' => '863070043386100',
            'date_time' => Carbon::createFromFormat('ymdHis', '240421070000')->addHours(3)->addMinutes(30),
        ], $result[0]);

        // Find entry with speed = 18
        $speedIndex = array_search(18, array_column($result, 'speed'));
        $this->assertNotFalse($speedIndex, 'Entry with speed 18 was not found');
        $this->assertEquals(18, $result[$speedIndex]['speed']);
        $this->assertEquals('103500', $result[$speedIndex]['date_time']->format('His'));

        // Entry with status = 0
        $statusIndex = 1; // Index for the 070100 entry with status 0
        $this->assertEquals(0, $result[$statusIndex]['status']);
        $this->assertEquals('103100', $result[$statusIndex]['date_time']->format('His'));

        // Verify coordinates are consistent across entries
        foreach ($result as $entry) {
            $this->assertEquals([34.884065, 50.599625], $entry['coordinate']);
        }
    }
}
