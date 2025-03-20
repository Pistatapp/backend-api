<?php

namespace Tests\Unit;

use Tests\TestCase;
use Carbon\Carbon;
use InvalidArgumentException;

class HelperFunctionsTest extends TestCase
{
    public function test_jalali_to_carbon()
    {
        $this->assertInstanceOf(Carbon::class, jalali_to_carbon('1402/01/01'));

        $this->expectException(InvalidArgumentException::class);
        jalali_to_carbon('invalid-date');
    }

    public function test_is_jalali_date()
    {
        $this->assertTrue(is_jalali_date('1402/01/01'));
        $this->assertFalse(is_jalali_date('1402-01-01'));
        $this->assertFalse(is_jalali_date('invalid-date'));
    }

    public function test_time_to_hours()
    {
        $this->assertEquals(1.5, time_to_hours('01:30'));
        $this->assertEquals(2.0, time_to_hours('02:00'));
        $this->assertEquals(0.5, time_to_hours('00:30'));
    }

    public function test_calculate_polygon_area()
    {
        // Test a simple square with corners at (0,0), (0,1), (1,1), (1,0)
        $square = [[0,0], [0,1], [1,1], [1,0]];
        $this->assertEquals(1.0, calculate_polygon_area($square));

        // Test triangle
        $triangle = [[0,0], [1,0], [0,1]];
        $this->assertEquals(0.5, calculate_polygon_area($triangle));

        // Test a rectangle
        $rectangle = [[0,0], [0,2], [3,2], [3,0]];
        $this->assertEquals(6.0, calculate_polygon_area($rectangle));

        // Test a pentagon
        $pentagon = [[0,0], [2,0], [3,1], [1,3], [-1,1]];
        $this->assertEquals(7.0, calculate_polygon_area($pentagon));

        // Test a concave polygon
        $concave = [[0,0], [4,0], [4,3], [2,1], [0,3]];
        $this->assertEquals(8.0, calculate_polygon_area($concave));

        // Test a self-intersecting polygon (should throw an exception)
        $selfIntersecting = [[0,0], [4,0], [2,2], [4,4], [0,4]];
        $this->expectException(InvalidArgumentException::class);
        calculate_polygon_area($selfIntersecting);

        // Test with less than 3 points
        $this->expectException(InvalidArgumentException::class);
        calculate_polygon_area([[0,0], [1,1]]);

        // Test with duplicate points
        $duplicatePoints = [[0,0], [0,1], [1,1], [1,0], [0,0]];
        $this->assertEquals(1.0, calculate_polygon_area($duplicatePoints));

        // Test with negative coordinates
        $negativeCoords = [[-1,-1], [-1,1], [1,1], [1,-1]];
        $this->assertEquals(4.0, calculate_polygon_area($negativeCoords));

        // Test with a regular octagon
        $octagon = [
            [2,0], [4,0], [6,2], [6,4],
            [4,6], [2,6], [0,4], [0,2]
        ];
        $this->assertEquals(28.0, calculate_polygon_area($octagon));

        // Test with a complex irregular shape (star-like)
        $star = [
            [3,0], [4,2], [6,2], [5,4],
            [6,6], [3,5], [0,6], [1,4],
            [0,2], [2,2]
        ];
        $this->assertEquals(22.0, calculate_polygon_area($star));

        // Test with floating point coordinates
        $floatingPoints = [[0.5,0.5], [1.5,0.5], [1.5,1.5], [0.5,1.5]];
        $this->assertEquals(1.0, calculate_polygon_area($floatingPoints));

        $this->assertEquals('00:30', to_time_format(30));
    }

    public function test_calculate_polygon_center()
    {
        // Test with a square
        $square = [[0,0], [2,0], [2,2], [0,2]];
        $this->assertEquals([1,1], calculate_polygon_center($square));

        // Test with a triangle
        $triangle = [[0,0], [3,0], [0,3]];
        $this->assertEquals([1,1], calculate_polygon_center($triangle));

        // Test with irregular pentagon
        $pentagon = [[0,0], [2,0], [3,1], [1,3], [-1,1]];
        $this->assertEquals([1,1], calculate_polygon_center($pentagon));

        // Test with floating point coordinates
        $floatingPoints = [[0.5,0.5], [1.5,0.5], [1.5,1.5], [0.5,1.5]];
        $this->assertEquals([1,1], calculate_polygon_center($floatingPoints));

        // Test with negative coordinates
        $negativeCoords = [[-2,-2], [-2,2], [2,2], [2,-2]];
        $this->assertEquals([0,0], calculate_polygon_center($negativeCoords));

        // Test with less than 3 points
        $this->expectException(\InvalidArgumentException::class);
        calculate_polygon_center([[0,0], [1,1]]);
    }

    public function test_weather_api()
    {
        $this->assertNotNull(weather_api());
    }

    public function test_get_model_class()
    {
        $this->assertEquals('App\\Models\\User', getModelClass('user'));
        $this->assertEquals('App\\Models\\User', getModelClass('User'));

        $this->expectException(InvalidArgumentException::class);
        getModelClass('invalid_model');
    }

    public function test_is_point_in_polygon()
    {
        // Test with a square from (0,0) to (2,2)
        $square = [[0,0], [0,2], [2,2], [2,0]];
        $this->assertTrue(is_point_in_polygon([1,1], $square));
        $this->assertFalse(is_point_in_polygon([3,3], $square));
        $this->assertTrue(is_point_in_polygon([0,1], $square));

        // Test with a regular octagon
        $octagon = [
            [2,0], [4,0], [6,2], [6,4],
            [4,6], [2,6], [0,4], [0,2]
        ];
        // Test points inside octagon
        $this->assertTrue(is_point_in_polygon([3,3], $octagon));
        $this->assertTrue(is_point_in_polygon([1,3], $octagon));
        // Test points on octagon edges
        $this->assertTrue(is_point_in_polygon([2,0], $octagon));
        $this->assertTrue(is_point_in_polygon([3,5.5], $octagon));
        // Test points outside octagon
        $this->assertFalse(is_point_in_polygon([7,7], $octagon));
        $this->assertFalse(is_point_in_polygon([-1,3], $octagon));

        // Test with a complex concave polygon (star-like shape)
        $complexConcave = [
            [4,0], [5,2], [8,2], [6,4],
            [7,7], [4,5], [1,7], [2,4],
            [0,2], [3,2]
        ];
        // Test points inside the concave regions
        $this->assertTrue(is_point_in_polygon([4,4], $complexConcave));
        $this->assertTrue(is_point_in_polygon([3,3], $complexConcave));
        // Test points in the "valleys" of the shape
        $this->assertFalse(is_point_in_polygon([1.5,4], $complexConcave));
        $this->assertFalse(is_point_in_polygon([6.5,4], $complexConcave));
        // Test points near vertices and edges (slightly inside)
        $this->assertTrue(is_point_in_polygon([4,0.1], $complexConcave));
        $this->assertTrue(is_point_in_polygon([4.9,2], $complexConcave));
    }

    public function test_calculate_distance()
    {
        // Test distance between same point (should be 0)
        $point = [35.6892, 51.3890]; // Tehran coordinates
        $this->assertEquals(0, calculate_distance($point, $point));

        // Test known distance between two cities
        // Tehran to Mashhad (approximate straight-line distance ~700-800 km)
        $tehran = [35.6892, 51.3890];
        $mashhad = [36.2972, 59.6067];
        $distance = calculate_distance($tehran, $mashhad);
        $this->assertGreaterThan(700, $distance);
        $this->assertLessThan(800, $distance);

        // Test distance between antipodal points
        // Roughly maximum possible distance on Earth (~20,000 km)
        $point1 = [0, 0];
        $point2 = [0, 180];
        $distance = calculate_distance($point1, $point2);
        $this->assertGreaterThan(19000, $distance);
        $this->assertLessThan(21000, $distance);

        // Test with negative coordinates
        $london = [51.5074, -0.1278];
        $paris = [48.8566, 2.3522];
        $distance = calculate_distance($london, $paris);
        $this->assertGreaterThan(300, $distance);
        $this->assertLessThan(400, $distance);
    }
}
