<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Remove absurd far-future gps_data rows (e.g. device year 2068) that pollute path queries.
 */
class GpsPurgeFutureJunkCommand extends Command
{
    protected $signature = 'gps:purge-future-junk
                            {--days=2 : Delete rows with date_time >= now + this many days}
                            {--dry-run : Only count, do not delete}
                            {--force : Required to actually delete}';

    protected $description = 'Purge far-future junk gps_data rows (bad device clocks)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            $countRow = DB::connection('mysql_gps')->selectOne(
                'SELECT COUNT(*) AS c FROM gps_data WHERE date_time >= (NOW() + INTERVAL ? DAY)',
                [$days]
            );
            $count = (int) ($countRow->c ?? 0);
        } catch (Throwable $e) {
            $this->error('mysql_gps query failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Far-future rows (date_time >= now + {$days}d): {$count}");

        if ($count === 0) {
            return self::SUCCESS;
        }

        if ($dryRun || ! $force) {
            $this->warn('Dry run / no --force — nothing deleted. Re-run with: php artisan gps:purge-future-junk --force');

            return self::SUCCESS;
        }

        try {
            $deleted = DB::connection('mysql_gps')->delete(
                'DELETE FROM gps_data WHERE date_time >= (NOW() + INTERVAL ? DAY)',
                [$days]
            );
            $this->info("Deleted {$deleted} row(s).");
        } catch (Throwable $e) {
            $this->error('Delete failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
