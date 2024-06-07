<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class FunctionsTest extends TestCase
{
    /**
     * Test the calculate_polygon_area function.
     */
    public function test_calculate_polygon_area(): void
    {
        // Test case 1: Square with side length 1
        $points = [[0, 0], [1, 0], [1, 1], [0, 1]];
        $this->assertEquals(1, calculate_polygon_area($points));

        // Test case 2: Rectangle with sides 2 and 3
        $points = [[0, 0], [2, 0], [2, 3], [0, 3]];
        $this->assertEquals(6, calculate_polygon_area($points));

        // Test case 3: Triangle with base 2 and height 3
        $points = [[0, 0], [2, 0], [1, 3]];
        $this->assertEquals(3, calculate_polygon_area($points));

        // Test case 4: Invalid polygon with less than 3 points
        $points = [[0, 0], [1, 1]];
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('A polygon must have at least 3 points');
        calculate_polygon_area($points);
    }
}
