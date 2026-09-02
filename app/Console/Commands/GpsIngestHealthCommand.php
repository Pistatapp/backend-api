<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Production GPS ingest health check (used by deploy.sh and ops).
 *
 * IMPORTANT: gps_data.p_future can hold 10M+ mixed-date rows. Full-table
 * COUNT(*) on far-future clocks can OOM / freeze a small VPS and drop SSH.
 * Deploy uses --fast (no full junk scan). Use --deep only when you can spare load.
 */
class GpsIngestHealthCommand extends Command
{
    protected $signature = 'gps:ingest-health
                            {--json : Machine-readable JSON summary}
                            {--fast : Skip heavy gps_data scans (default for deploy.sh)}
                            {--deep : Allow full far-future COUNT on gps_data (can be slow/heavy)}';

    protected $description = 'Check Redis, queues, mysql_gps, partitions, code gates, and today ingest counts';

    public function handle(): int
    {
        $ok = true;
        $fast = (bool) $this->option('fast') || ! $this->option('deep');
        // --deep wins over --fast when both passed
        if ($this->option('deep')) {
            $fast = false;
        }

        $report = [
            'mode' => $fast ? 'fast' : 'deep',
            'redis' => null,
            'queues' => [],
            'mysql_gps' => null,
            'unique_index' => null,
            'partition_future' => null,
            'today_sane_rows' => null,
            'future_junk_rows' => null,
            'clock_resync_code' => null,
            'gps_ingest_driver' => config('services.gps_ingest.driver'),
        ];

        if (! $this->option('json')) {
            $this->info('=== GPS ingest health ('.$report['mode'].') ===');
        }

        // Redis + queues
        try {
            $pong = Redis::connection()->ping();
            $pongStr = is_object($pong) ? (string) $pong : (string) json_encode($pong);
            $report['redis'] = $pongStr;
            if (! $this->option('json')) {
                $this->line("Redis ping: {$pongStr}");
            }

            foreach (['gps-processing', 'gps-broadcast', 'gps-side-effects', 'default'] as $queue) {
                $len = (int) Redis::llen("queues:{$queue}");
                $report['queues'][$queue] = $len;
                if (! $this->option('json')) {
                    $this->line("Queue queues:{$queue} length: {$len}");
                }
                if ($queue === 'gps-processing' && $len > 5000) {
                    $ok = false;
                    if (! $this->option('json')) {
                        $this->error("gps-processing backlog too high ({$len})");
                    }
                }
            }
        } catch (Throwable $e) {
            $ok = false;
            $report['redis'] = 'FAILED: '.$e->getMessage();
            if (! $this->option('json')) {
                $this->error('Redis FAILED: '.$e->getMessage());
                if (str_contains(strtolower($e->getMessage()), 'loading')) {
                    $this->warn('Redis is still LOADING — do not replay gateway traffic yet.');
                }
            }
        }

        // Code gate: device timestamps must be preserved (offline/history safety).
        $ingestPath = app_path('Jobs/IngestGpsData.php');
        $hasTimestampPolicy = File::exists($ingestPath)
            && str_contains(File::get($ingestPath), 'normalizeDeviceDateTime')
            && str_contains(File::get($ingestPath), 'retaining device timestamp');
        $report['clock_resync_code'] = $hasTimestampPolicy;
        if (! $hasTimestampPolicy) {
            $ok = false;
            if (! $this->option('json')) {
                $this->error('IngestGpsData timestamp-preservation policy is missing');
            }
        } elseif (! $this->option('json')) {
            $this->line('Device timestamp preservation code: present');
        }

        if (! $this->option('json')) {
            $this->line('GPS_INGEST_DRIVER: '.$report['gps_ingest_driver']);
        }
        if ($report['gps_ingest_driver'] === 'go' && ! $this->option('json')) {
            $this->warn('Driver=go — Laravel queue path bypassed; verify Go gps-ingest separately');
        }

        // mysql_gps
        try {
            $db = (string) config('database.connections.mysql_gps.database');
            $conn = DB::connection('mysql_gps');
            $conn->selectOne('SELECT 1 AS ok');
            // Cap SELECT runtime (MySQL 5.7.8+ / 8.x) so a bad scan cannot freeze the host.
            try {
                $conn->statement('SET SESSION MAX_EXECUTION_TIME=8000');
            } catch (Throwable) {
                // ignore if unsupported
            }

            $report['mysql_gps'] = $db;
            if (! $this->option('json')) {
                $this->line("mysql_gps database: {$db} — OK");
            }

            $unique = $conn->selectOne('
                SELECT COUNT(*) AS c
                FROM information_schema.statistics
                WHERE table_schema = ? AND table_name = ? AND index_name = ?
            ', [$db, 'gps_data', 'gps_data_imei_date_time_unique']);
            $hasUnique = ((int) ($unique->c ?? 0)) > 0;
            $report['unique_index'] = $hasUnique;
            if (! $this->option('json')) {
                $this->line('Unique imei+date_time: '.($hasUnique ? 'YES' : 'NO (plain insert mode)'));
            }

            $futurePart = $conn->selectOne('
                SELECT COUNT(*) AS c
                FROM information_schema.PARTITIONS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND PARTITION_NAME = ?
            ', [$db, 'gps_data', 'p_future']);
            $hasFuture = ((int) ($futurePart->c ?? 0)) > 0;
            $report['partition_future'] = $hasFuture;
            if (! $hasFuture) {
                $ok = false;
                if (! $this->option('json')) {
                    $this->error('Partition p_future missing — new-day inserts may fail');
                }
            } elseif (! $this->option('json')) {
                $this->line('Partition p_future: present');
            }

            // Today window — avoid COUNT(DISTINCT) (extra heavy on large partitions).
            $today = $conn->selectOne('
                SELECT COUNT(*) AS c, MAX(date_time) AS max_dt
                FROM gps_data
                WHERE date_time >= CURDATE()
                  AND date_time < (CURDATE() + INTERVAL 2 DAY)
            ');
            $todayCount = (int) ($today->c ?? 0);
            $report['today_sane_rows'] = [
                'count' => $todayCount,
                'max' => $today->max_dt ?? null,
            ];
            if (! $this->option('json')) {
                $this->line(sprintf(
                    'Rows today (sane < +2d): %d | max=%s',
                    $todayCount,
                    $report['today_sane_rows']['max'] ?? 'null'
                ));
                if ($todayCount === 0) {
                    $this->warn('No sane GPS rows for today yet — after workers are stable, replay IoT Gateway logs');
                }
            }

            if ($fast) {
                // Do NOT full-scan p_future for junk COUNT — that partition can be 10M+ rows
                // and has OOM'd / dropped SSH on eco1-small during deploy.
                $report['future_junk_rows'] = null;
                if (! $this->option('json')) {
                    $this->line('Far-future junk scan: skipped (--fast). Use: php artisan gps:purge-future-junk --dry-run');
                    $this->line('Deep health (heavy): php artisan gps:ingest-health --deep');
                }
            } else {
                $futureJunk = $conn->selectOne('
                    SELECT COUNT(*) AS c
                    FROM gps_data
                    WHERE date_time >= (NOW() + INTERVAL 2 DAY)
                ');
                $junk = (int) ($futureJunk->c ?? 0);
                $report['future_junk_rows'] = $junk;
                if ($junk > 0 && ! $this->option('json')) {
                    $this->warn("Far-future junk rows (date_time >= now+2d): {$junk}");
                    $this->warn('Purge with: php artisan gps:purge-future-junk --dry-run  then  --force');
                } elseif (! $this->option('json')) {
                    $this->line('Far-future junk rows: 0');
                }
            }
        } catch (Throwable $e) {
            $ok = false;
            $report['mysql_gps'] = 'FAILED: '.$e->getMessage();
            if (! $this->option('json')) {
                $this->error('mysql_gps FAILED: '.$e->getMessage());
                if (str_contains(strtolower($e->getMessage()), 'maximum statement execution time')) {
                    $this->warn('Query timed out — gps_data scan too heavy; stick to --fast / purge offline');
                }
            }
        }

        if ($this->option('json')) {
            $report['ok'] = $ok;
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->newLine();
            $this->line('Also verify on host: sudo supervisorctl status | grep gps');
            $this->line('Must include RUNNING gps-processing_* and gps-broadcast_*.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
