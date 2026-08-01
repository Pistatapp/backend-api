<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ensure mysql_gps.gps_data has daily RANGE partitions covering today + N days.
 *
 * Without p_future (or when the MySQL EVENT dies), inserts for new calendar days
 * fail with "Table has no partition for value from KEY of ..." while WebSocket
 * broadcast can still succeed from an in-memory payload — markers move, path dies.
 */
class EnsureGpsDataPartitions extends Command
{
    protected $signature = 'gps:ensure-partitions
                            {--days=14 : How many days ahead (including today) to guarantee}
                            {--dry-run : Only report missing partitions}';

    protected $description = 'Create missing daily partitions on mysql_gps.gps_data (keeps p_future)';

    public function handle(): int
    {
        if (! $this->gpsTableReady()) {
            $this->warn('mysql_gps.gps_data is not available — skipped.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');

        try {
            $existing = $this->existingPartitionNames();
        } catch (Throwable $e) {
            $this->error('Failed to list partitions: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($existing === [] || ! in_array('p_future', $existing, true)) {
            $this->error('gps_data is not RANGE-partitioned with p_future. Manual DBA review required.');
            Log::error('gps:ensure-partitions aborted — missing p_future', [
                'existing' => $existing,
            ]);

            return self::FAILURE;
        }

        $created = 0;
        $today = Carbon::today(config('app.timezone', 'Asia/Tehran'));
        // Backfill a week so Jul 31 is created when "today" is Aug 1 and EVENT was dead.
        $startOffset = -7;

        for ($i = $startOffset; $i < $days; $i++) {
            $date = $today->copy()->addDays($i);
            $name = 'p'.$date->format('Ymd');
            // RANGE VALUES LESS THAN next civil day (YYYYMMDD as int)
            $lessThan = (int) $date->copy()->addDay()->format('Ymd');

            if (in_array($name, $existing, true)) {
                continue;
            }

            $this->line(($dryRun ? '[dry-run] would create ' : 'Creating ')."{$name} LESS THAN {$lessThan}");

            if ($dryRun) {
                $created++;
                continue;
            }

            try {
                $sql = sprintf(
                    'ALTER TABLE gps_data REORGANIZE PARTITION p_future INTO (
                        PARTITION %s VALUES LESS THAN (%d),
                        PARTITION p_future VALUES LESS THAN MAXVALUE
                    )',
                    $name,
                    $lessThan
                );
                DB::connection('mysql_gps')->statement($sql);
                $existing[] = $name;
                $created++;
            } catch (Throwable $e) {
                // Concurrent ensure / already created / out-of-order LESS THAN
                $lower = strtolower($e->getMessage());
                if (str_contains($lower, 'duplicate')
                    || str_contains($lower, 'already')
                    || str_contains($lower, 'values less than')
                    || str_contains($lower, 'maxvalue')) {
                    $this->warn("Skip {$name}: ".$e->getMessage());
                    continue;
                }

                Log::error('gps:ensure-partitions failed', [
                    'partition' => $name,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Failed {$name}: ".$e->getMessage());

                return self::FAILURE;
            }
        }

        $this->info($dryRun
            ? "Dry-run complete — {$created} partition(s) missing."
            : "Done — created {$created} partition(s).");

        return self::SUCCESS;
    }

    private function gpsTableReady(): bool
    {
        try {
            $connection = config('database.connections.mysql_gps');
            if (($connection['driver'] ?? null) !== 'mysql') {
                return false;
            }

            return Schema::connection('mysql_gps')->hasTable('gps_data');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function existingPartitionNames(): array
    {
        $database = config('database.connections.mysql_gps.database');
        $rows = DB::connection('mysql_gps')->select('
            SELECT PARTITION_NAME AS name
            FROM information_schema.PARTITIONS
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = ?
              AND PARTITION_NAME IS NOT NULL
            ORDER BY PARTITION_ORDINAL_POSITION
        ', [$database, 'gps_data']);

        return array_values(array_filter(array_map(
            static fn ($r) => (string) ($r->name ?? ''),
            $rows
        )));
    }
}
