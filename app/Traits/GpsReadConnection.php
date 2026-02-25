<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Provides read-optimized database connection for GPS data queries.
 *
 * Uses READ UNCOMMITTED isolation level to prevent write operations
 * from blocking read queries. This allows "dirty reads" but significantly
 * improves read performance during heavy write loads.
 *
 * For GPS tracking data, eventual consistency is acceptable since:
 * - Data is append-only (no updates to existing records)
 * - Slight delays in visibility are tolerable for path/analytics queries
 * - The performance gain outweighs the minimal risk of reading uncommitted data
 */
trait GpsReadConnection
{
    private const GPS_READ_CONNECTION = 'mysql_gps_read';

    private ?PDO $gpsReadPdo = null;
    private bool $isolationLevelSet = false;

    /**
     * Get the GPS read-optimized database connection.
     */
    protected function getGpsReadConnection(): \Illuminate\Database\Connection
    {
        $connection = DB::connection(self::GPS_READ_CONNECTION);

        if (!$this->isolationLevelSet) {
            $this->setReadUncommittedIsolation($connection);
            $this->isolationLevelSet = true;
        }

        return $connection;
    }

    /**
     * Get raw PDO instance with READ UNCOMMITTED isolation for streaming queries.
     * Uses unbuffered queries for memory-efficient streaming of large result sets.
     */
    protected function getGpsReadPdo(): PDO
    {
        if ($this->gpsReadPdo === null) {
            $connection = $this->getGpsReadConnection();
            $this->gpsReadPdo = $connection->getPdo();

            $this->gpsReadPdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }

        return $this->gpsReadPdo;
    }

    /**
     * Execute a read query on the GPS read connection.
     *
     * @param string $query SQL query with placeholders
     * @param array $bindings Query parameter bindings
     * @return array Query results
     */
    protected function gpsReadSelect(string $query, array $bindings = []): array
    {
        return $this->getGpsReadConnection()->select($query, $bindings);
    }

    /**
     * Execute a read query and return the first result.
     *
     * @param string $query SQL query with placeholders
     * @param array $bindings Query parameter bindings
     * @return object|null First result or null
     */
    protected function gpsReadSelectOne(string $query, array $bindings = []): ?object
    {
        $results = $this->gpsReadSelect($query, $bindings);
        return $results[0] ?? null;
    }

    /**
     * Get the GPS read query builder for a table.
     *
     * @param string $table Table name
     * @return \Illuminate\Database\Query\Builder
     */
    protected function gpsReadTable(string $table): \Illuminate\Database\Query\Builder
    {
        return $this->getGpsReadConnection()->table($table);
    }

    /**
     * Set READ UNCOMMITTED isolation level on the connection.
     * This allows reading data that hasn't been committed yet,
     * preventing read queries from being blocked by write transactions.
     */
    private function setReadUncommittedIsolation(\Illuminate\Database\Connection $connection): void
    {
        $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');
    }

    /**
     * Restore buffered query mode on PDO connection.
     * Call this after completing streaming operations.
     */
    protected function restoreBufferedQueryMode(): void
    {
        if ($this->gpsReadPdo !== null) {
            $this->gpsReadPdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    }
}
