<?php

namespace Tests\Feature\Services;

use Tests\TestCase;
use App\Services\ParseDataService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ParseDataServiceTest extends TestCase
{
    use RefreshDatabase;

    private ParseDataService $parseDataService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parseDataService = new ParseDataService();
    }

    #[Test]
    public function it_parses_valid_gps_data_correctly()
    {
        $today = date('ymd');
        $rawData = json_encode([
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,' . $today . ',070000,000,000,1,0,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,' . $today . ',070100,003,000,1,90,0,863070043386100']
        ]);

        $result = $this->parseDataService->parse($rawData);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);

        $firstReport = $result[0];
        $this->assertEquals([34.883333, 50.583333], $firstReport['coordinate']);
        $this->assertEquals(0, $firstReport['speed']); // Index 6: 000
        $this->assertEquals(1, $firstReport['status']); // Index 8: 1
        $this->assertEquals(0, $firstReport['directions']['ew']); // Index 9: 0
        $this->assertEquals(0, $firstReport['directions']['ns']); // Index 10: 0
        $this->assertEquals('863070043386100', $firstReport['imei']); // Index 11: 863070043386100
        $this->assertTrue($firstReport['is_stopped']);
        $this->assertFalse($firstReport['is_off']);
        $this->assertFalse($firstReport['is_starting_point']);
        $this->assertFalse($firstReport['is_ending_point']);
        $this->assertEquals(0, $firstReport['stoppage_time']);
        $this->assertInstanceOf(Carbon::class, $firstReport['date_time']);
    }

    #[Test]
    public function it_converts_nmea_coordinates_to_decimal_degrees()
    {
        $today = date('ymd');
        $rawData = json_encode([
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,' . $today . ',070000,000,000,1,0,0,863070043386100']
        ]);

        $result = $this->parseDataService->parse($rawData);
        $coordinate = $result[0]['coordinate'];

        // 3453.00000 NMEA = 34°53.00000' = 34.883333°
        // 05035.0000 NMEA = 50°35.0000' = 50.583333°
        $this->assertEquals(34.883333, $coordinate[0], '', 0.000001);
        $this->assertEquals(50.583333, $coordinate[1], '', 0.000001);
    }

    #[Test]
    public function it_applies_timezone_offset_correctly()
    {
        $today = date('ymd');
        $rawData = json_encode([
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,' . $today . ',070000,000,000,1,0,0,863070043386100']
        ]);

        $result = $this->parseDataService->parse($rawData);
        $dateTime = $result[0]['date_time'];

        // GPS time: today,070000 (today 07:00:00)
        // Expected local time: today 10:30:00 (GPS + 3:30)
        $expected = Carbon::today()->setTime(10, 30, 0);
        $this->assertEquals($expected->format('Y-m-d H:i:s'), $dateTime->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_filters_out_non_today_data()
    {
        $yesterday = Carbon::yesterday();
        $yesterdayFormatted = $yesterday->format('ymd');
        $today = date('ymd');

        $rawData = json_encode([
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,' . $yesterdayFormatted . ',070000,000,000,1,0,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,' . $today . ',070100,003,000,1,090,0,863070043386100']
        ]);

        $result = $this->parseDataService->parse($rawData);

        $this->assertCount(1, $result);
        $this->assertEquals('863070043386100', $result[0]['imei']);
    }

    #[Test]
    public function it_sorts_reports_by_datetime()
    {
        $today = date('ymd');
        $rawData = json_encode([
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,' . $today . ',070200,000,000,1,0,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,' . $today . ',070000,000,000,1,090,0,863070043386100'],
            ['data' => '+Hooshnic:V1.03,3453.02000,05035.0200,000,' . $today . ',070100,000,000,1,180,0,863070043386100']
        ]);

        $result = $this->parseDataService->parse($rawData);

        $this->assertCount(3, $result);

        // Check chronological order
        $this->assertTrue($result[0]['date_time']->lt($result[1]['date_time']));
        $this->assertTrue($result[1]['date_time']->lt($result[2]['date_time']));
    }

    #[Test]
    public function it_handles_invalid_data_format()
    {
        $today = date('ymd');
        $rawData = json_encode([
            ['data' => 'invalid_format'],
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,' . $today . ',070000,000,000,1,0,0,863070043386100']
        ]);

        $result = $this->parseDataService->parse($rawData);

        $this->assertCount(1, $result);
        $this->assertEquals('863070043386100', $result[0]['imei']);
    }

    #[Test]
    public function it_handles_malformed_json()
    {
        $today = date('ymd');
        $rawData = '{"data": "+Hooshnic:V1.03,3453.00000,05035.0000,000,' . $today . ',070000,000,000,1,0,0,863070043386100"}{"data": "+Hooshnic:V1.03,3453.01000,05035.0100,000,' . $today . ',070100,003,000,1,90,0,863070043386100"}';

        $this->expectException(\JsonException::class);

        $this->parseDataService->parse($rawData);
    }

    #[Test]
    public function it_determines_stopped_status_correctly()
    {
        $today = date('ymd');
        $rawData = json_encode([
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,' . $today . ',070000,000,000,1,0,0,863070043386100'], // speed 0 = stopped
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,' . $today . ',070100,005,000,1,090,0,863070043386100']  // speed 5 = moving
        ]);

        $result = $this->parseDataService->parse($rawData);

        $this->assertTrue($result[0]['is_stopped']);
        $this->assertFalse($result[1]['is_stopped']);
    }

    #[Test]
    public function it_determines_off_status_correctly()
    {
        $today = date('ymd');
        $rawData = json_encode([
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,' . $today . ',070000,000,000,0,0,0,863070043386100'], // status 0 = off
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,' . $today . ',070100,005,000,1,090,0,863070043386100']  // status 1 = on
        ]);

        $result = $this->parseDataService->parse($rawData);

        $this->assertTrue($result[0]['is_off']);
        $this->assertFalse($result[1]['is_off']);
    }

    #[Test]
    public function it_handles_empty_data()
    {
        $this->expectException(\JsonException::class);

        $this->parseDataService->parse('');
    }

    #[Test]
    public function it_handles_invalid_json()
    {
        $this->expectException(\JsonException::class);

        $this->parseDataService->parse('invalid json');
    }

    #[Test]
    public function it_validates_data_format_with_regex()
    {
        $today = date('ymd');
        $validData = '+Hooshnic:V1.03,3453.00000,05035.0000,000,' . $today . ',070000,000,000,1,0,0,863070043386100';
        $invalidData = 'invalid_format';

        $rawData = json_encode([
            ['data' => $validData],
            ['data' => $invalidData]
        ]);

        $result = $this->parseDataService->parse($rawData);

        $this->assertCount(1, $result);
        $this->assertEquals('863070043386100', $result[0]['imei']); // This would be the IMEI from valid data
    }

    #[Test]
    public function it_handles_different_speed_values()
    {
        $today = date('ymd');
        $rawData = json_encode([
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,' . $today . ',070000,000,000,1,0,0,863070043386100'], // speed 0
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,' . $today . ',070100,005,000,1,090,0,863070043386100'], // speed 5
            ['data' => '+Hooshnic:V1.03,3453.02000,05035.0200,000,' . $today . ',070200,025,000,1,180,0,863070043386100'], // speed 25
            ['data' => '+Hooshnic:V1.03,3453.03000,05035.0300,000,' . $today . ',070300,100,000,1,270,0,863070043386100']  // speed 100
        ]);

        $result = $this->parseDataService->parse($rawData);

        $this->assertEquals(0, $result[0]['speed']);
        $this->assertEquals(5, $result[1]['speed']);
        $this->assertEquals(25, $result[2]['speed']);
        $this->assertEquals(100, $result[3]['speed']);
    }

    #[Test]
    public function it_handles_different_direction_values()
    {
        $today = date('ymd');
        $rawData = json_encode([
            ['data' => '+Hooshnic:V1.03,3453.00000,05035.0000,000,' . $today . ',070000,000,000,1,0,0,863070043386100'], // ew:0, ns:0
            ['data' => '+Hooshnic:V1.03,3453.01000,05035.0100,000,' . $today . ',070100,005,000,1,090,0,863070043386100'], // ew:90, ns:0
            ['data' => '+Hooshnic:V1.03,3453.02000,05035.0200,000,' . $today . ',070200,010,000,1,180,0,863070043386100'], // ew:180, ns:0
            ['data' => '+Hooshnic:V1.03,3453.03000,05035.0300,000,' . $today . ',070300,015,000,1,270,1,863070043386100']  // ew:270, ns:1
        ]);

        $result = $this->parseDataService->parse($rawData);

        $this->assertEquals(0, $result[0]['directions']['ew']);
        $this->assertEquals(0, $result[0]['directions']['ns']);

        $this->assertEquals(90, $result[1]['directions']['ew']);
        $this->assertEquals(0, $result[1]['directions']['ns']);

        $this->assertEquals(180, $result[2]['directions']['ew']);
        $this->assertEquals(0, $result[2]['directions']['ns']);

        $this->assertEquals(270, $result[3]['directions']['ew']);
        $this->assertEquals(1, $result[3]['directions']['ns']);
    }
}
