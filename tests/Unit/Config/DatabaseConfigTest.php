<?php

namespace Tests\Unit\Config;

use PDO;
use Tests\TestCase;

/**
 * Tests for database configuration, specifically the GPS read/write connections.
 */
class DatabaseConfigTest extends TestCase
{
    /**
     * Test mysql_gps connection configuration exists.
     */
    public function test_mysql_gps_connection_exists(): void
    {
        $config = config('database.connections.mysql_gps');

        $this->assertNotNull($config);
        $this->assertEquals('mysql', $config['driver']);
    }

    /**
     * Test mysql_gps_read connection configuration exists.
     */
    public function test_mysql_gps_read_connection_exists(): void
    {
        $config = config('database.connections.mysql_gps_read');

        $this->assertNotNull($config);
        $this->assertEquals('mysql', $config['driver']);
    }

    /**
     * Test mysql_gps_read has unbuffered queries enabled.
     */
    public function test_mysql_gps_read_has_unbuffered_queries(): void
    {
        $config = config('database.connections.mysql_gps_read');

        $this->assertArrayHasKey('options', $config);
        $this->assertArrayHasKey(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $config['options']);
        $this->assertFalse($config['options'][PDO::MYSQL_ATTR_USE_BUFFERED_QUERY]);
    }

    /**
     * Test mysql_gps_read has sticky disabled.
     */
    public function test_mysql_gps_read_has_sticky_disabled(): void
    {
        $config = config('database.connections.mysql_gps_read');

        $this->assertArrayHasKey('sticky', $config);
        $this->assertFalse($config['sticky']);
    }

    /**
     * Test mysql_gps_read uses same database as mysql_gps.
     */
    public function test_mysql_gps_read_uses_same_database(): void
    {
        $writeConfig = config('database.connections.mysql_gps');
        $readConfig = config('database.connections.mysql_gps_read');

        $this->assertEquals($writeConfig['database'], $readConfig['database']);
    }

    /**
     * Test mysql_gps_read supports separate read host configuration via env.
     */
    public function test_mysql_gps_read_supports_separate_read_host(): void
    {
        $config = config('database.connections.mysql_gps_read');

        $this->assertArrayHasKey('host', $config);
        $this->assertNotNull($config['host']);
    }

    /**
     * Test mysql_gps_read has persistent connections enabled.
     */
    public function test_mysql_gps_read_has_persistent_connections(): void
    {
        $config = config('database.connections.mysql_gps_read');

        $this->assertArrayHasKey('options', $config);
        $this->assertArrayHasKey(PDO::ATTR_PERSISTENT, $config['options']);
        $this->assertTrue($config['options'][PDO::ATTR_PERSISTENT]);
    }

    /**
     * Test mysql_gps has pool configuration for connection pooling.
     */
    public function test_mysql_gps_has_pool_configuration(): void
    {
        $config = config('database.connections.mysql_gps');

        $this->assertArrayHasKey('pool', $config);
        $this->assertArrayHasKey('min_connections', $config['pool']);
        $this->assertArrayHasKey('max_connections', $config['pool']);
    }

    /**
     * Test mysql_gps_read has non-strict mode.
     */
    public function test_mysql_gps_read_has_non_strict_mode(): void
    {
        $config = config('database.connections.mysql_gps_read');

        $this->assertArrayHasKey('strict', $config);
        $this->assertFalse($config['strict']);
    }

    /**
     * Test both connections use UTF8MB4 charset.
     */
    public function test_both_connections_use_utf8mb4(): void
    {
        $writeConfig = config('database.connections.mysql_gps');
        $readConfig = config('database.connections.mysql_gps_read');

        $this->assertEquals('utf8mb4', $writeConfig['charset']);
        $this->assertEquals('utf8mb4', $readConfig['charset']);
    }

    /**
     * Test environment variable fallbacks for read connection.
     */
    public function test_read_connection_environment_variable_structure(): void
    {
        $config = config('database.connections.mysql_gps_read');

        $this->assertNotNull($config['host']);
        $this->assertNotNull($config['port']);
        $this->assertNotNull($config['database']);
        $this->assertNotNull($config['username']);
    }
}
