<?php

namespace Tests\Unit\Traits;

use App\Traits\GpsReadConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * Unit tests for the GpsReadConnection trait.
 *
 * These tests verify that the trait methods work correctly when MySQL
 * is available. Tests will be skipped if the mysql_gps_read connection
 * is not properly configured.
 */
class GpsReadConnectionTest extends TestCase
{
    use GpsReadConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolationLevelSet = false;
        $this->gpsReadPdo = null;
    }

    /**
     * Skip test if MySQL GPS read connection is not configured.
     */
    private function skipIfMysqlNotAvailable(): void
    {
        try {
            $config = config('database.connections.mysql_gps_read');
            if (empty($config) || $config['driver'] !== 'mysql') {
                $this->markTestSkipped('mysql_gps_read connection not configured.');
            }

            // Try to actually connect
            DB::connection('mysql_gps_read')->getPdo();
        } catch (\Exception $e) {
            $this->markTestSkipped('MySQL GPS read connection not available: ' . $e->getMessage());
        }
    }

    /**
     * Test that getGpsReadConnection returns the correct connection.
     */
    public function test_get_gps_read_connection_returns_correct_connection(): void
    {
        $this->skipIfMysqlNotAvailable();
        $connection = $this->getGpsReadConnection();

        $this->assertInstanceOf(\Illuminate\Database\Connection::class, $connection);
        $this->assertEquals('mysql_gps_read', $connection->getName());
    }

    /**
     * Test that getGpsReadConnection sets READ UNCOMMITTED isolation level.
     */
    public function test_get_gps_read_connection_sets_read_uncommitted_isolation(): void
    {
        $this->skipIfMysqlNotAvailable();
        $connection = $this->getGpsReadConnection();

        $result = $connection->selectOne('SELECT @@SESSION.transaction_isolation as isolation');

        $this->assertEquals('READ-UNCOMMITTED', $result->isolation);
    }

    /**
     * Test that isolation level is only set once per instance.
     */
    public function test_isolation_level_is_set_only_once(): void
    {
        $this->skipIfMysqlNotAvailable();
        $this->assertFalse($this->isolationLevelSet);

        $this->getGpsReadConnection();
        $this->assertTrue($this->isolationLevelSet);

        $this->getGpsReadConnection();
        $this->assertTrue($this->isolationLevelSet);
    }

    /**
     * Test that getGpsReadPdo returns a PDO instance.
     */
    public function test_get_gps_read_pdo_returns_pdo_instance(): void
    {
        $this->skipIfMysqlNotAvailable();
        $pdo = $this->getGpsReadPdo();

        $this->assertInstanceOf(PDO::class, $pdo);
    }

    /**
     * Test that getGpsReadPdo sets unbuffered query mode.
     */
    public function test_get_gps_read_pdo_sets_unbuffered_query_mode(): void
    {
        $this->skipIfMysqlNotAvailable();
        $pdo = $this->getGpsReadPdo();

        $this->assertFalse(
            $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY)
        );
    }

    /**
     * Test that gpsReadSelect executes queries correctly.
     */
    public function test_gps_read_select_executes_queries(): void
    {
        $this->skipIfMysqlNotAvailable();
        $result = $this->gpsReadSelect('SELECT 1 + 1 as result');

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals(2, $result[0]->result);
    }

    /**
     * Test that gpsReadSelect handles parameterized queries.
     */
    public function test_gps_read_select_handles_parameterized_queries(): void
    {
        $this->skipIfMysqlNotAvailable();
        $result = $this->gpsReadSelect('SELECT ? + ? as result', [3, 4]);

        $this->assertIsArray($result);
        $this->assertEquals(7, $result[0]->result);
    }

    /**
     * Test that gpsReadSelectOne returns single result.
     */
    public function test_gps_read_select_one_returns_single_result(): void
    {
        $this->skipIfMysqlNotAvailable();
        $result = $this->gpsReadSelectOne('SELECT 42 as answer');

        $this->assertIsObject($result);
        $this->assertEquals(42, $result->answer);
    }

    /**
     * Test that gpsReadSelectOne returns null for empty result.
     */
    public function test_gps_read_select_one_returns_null_for_empty_result(): void
    {
        $this->skipIfMysqlNotAvailable();
        $result = $this->gpsReadSelectOne('SELECT 1 WHERE 1 = 0');

        $this->assertNull($result);
    }

    /**
     * Test that gpsReadTable returns a query builder.
     */
    public function test_gps_read_table_returns_query_builder(): void
    {
        $this->skipIfMysqlNotAvailable();
        $builder = $this->gpsReadTable('gps_data');

        $this->assertInstanceOf(\Illuminate\Database\Query\Builder::class, $builder);
    }

    /**
     * Test that restoreBufferedQueryMode restores buffered mode.
     */
    public function test_restore_buffered_query_mode(): void
    {
        $this->skipIfMysqlNotAvailable();
        $pdo = $this->getGpsReadPdo();

        $this->assertFalse($pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY));

        $this->restoreBufferedQueryMode();

        $this->assertTrue($pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY));
    }

    /**
     * Test that restoreBufferedQueryMode handles null PDO gracefully.
     * This test doesn't require MySQL connection.
     */
    public function test_restore_buffered_query_mode_handles_null_pdo(): void
    {
        $this->gpsReadPdo = null;

        // This should not throw
        $this->restoreBufferedQueryMode();

        $this->assertNull($this->gpsReadPdo);
    }

    /**
     * Test that read connection uses same database as write connection.
     */
    public function test_read_connection_uses_same_database(): void
    {
        $this->skipIfMysqlNotAvailable();

        try {
            $writeConnection = DB::connection('mysql_gps');
            $writeConnection->getPdo(); // Ensure connection works
        } catch (\Exception $e) {
            $this->markTestSkipped('mysql_gps write connection not available.');
        }

        $readConnection = $this->getGpsReadConnection();

        $readDb = $readConnection->getDatabaseName();
        $writeDb = $writeConnection->getDatabaseName();

        $this->assertEquals($writeDb, $readDb);
    }
}
