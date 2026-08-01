<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Production GPS ingest health check (used by deploy.sh and ops).
 */
class GpsIngestHealthCommand extends Command
{
    protected $signature = 'gps:ingest-health {--json : Machine-readable JSON summary}';

    protected $description = 'Check Redis, queues, mysql_gps, partitions, code gates, and today ingest counts';

    public function handle(): int
    {
        $ok = true;
        $report = [
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
            $this->info('=== GPS ingest health ===');
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

        // Code gate: clock resync must exist (stuck Jul-30 / 2068 clocks)
        $ingestPath = app_path('Jobs/IngestGpsData.php');
        $hasResync = File::exists($ingestPath) && str_contains(File::get($ingestPath), 'normalizeDeviceDateTime');
        $report['clock_resync_code'] = $hasResync;
        if (! $hasResync) {
            $ok = false;
            if (! $this->option('json')) {
                $this->error('IngestGpsData missing normalizeDeviceDateTime — stuck clocks will not land on today');
            }
        } elseif (! $this->option('json')) {
            $this->line('Clock resync code: present');
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
            DB::connection('mysql_gps')->selectOne('SELECT 1 AS ok');
            $report['mysql_gps'] = $db;
            if (! $this->option('json')) {
                $this->line("mysql_gps database: {$db} — OK");
            }

            $unique = DB::connection('mysql_gps')->selectOne('
                SELECT COUNT(*) AS c
                FROM information_schema.statistics
                WHERE table_schema = ? AND table_name = ? AND index_name = ?
            ', [$db, 'gps_data', 'gps_data_imei_date_time_unique']);
            $hasUnique = ((int) ($unique->c ?? 0)) > 0;
            $report['unique_index'] = $hasUnique;
            if (! $this->option('json')) {
                $this->line('Unique imei+date_time: '.($hasUnique ? 'YES' : 'NO (plain insert mode)'));
            }

            $futurePart = DB::connection('mysql_gps')->selectOne('
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

            // Sane "today" window excludes absurd future clocks (e.g. 2068)
            $today = DB::connection('mysql_gps')->selectOne('
                SELECT COUNT(*) AS c, MAX(date_time) AS max_dt, COUNT(DISTINCT tractor_id) AS tractors
                FROM gps_data
                WHERE date_time >= CURDATE()
                  AND date_time < (CURDATE() + INTERVAL 2 DAY)
            ');
            $report['today_sane_rows'] = [
                'count' => (int) ($today->c ?? 0),
                'max' => $today->max_dt ?? null,
                'tractors' => (int) ($today->tractors ?? 0),
            ];
            $todayCount = $report['today_sane_rows']['count'];
            if (! $this->option('json')) {
                $this->line(sprintf(
                    'Rows today (sane < +2d): %d | tractors=%d | max=%s',
                    $todayCount,
                    $report['today_sane_rows']['tractors'],
                    $report['today_sane_rows']['max'] ?? 'null'
                ));
                if ($todayCount === 0) {
                    $this->warn('No sane GPS rows for today yet — after workers are stable, replay IoT Gateway logs');
                }
            }

            $futureJunk = DB::connection('mysql_gps')->selectOne('
                SELECT COUNT(*) AS c
                FROM gps_data
                WHERE date_time >= (NOW() + INTERVAL 2 DAY)
            ');
            $junk = (int) ($futureJunk->c ?? 0);
            $report['future_junk_rows'] = $junk;
            if ($junk > 0 && ! $this->option('json')) {
                $this->warn("Far-future junk rows (date_time >= now+2d): {$junk}");
                $this->warn('Purge with: php artisan gps:purge-future-junk --dry-run  then  --force');
            }
        } catch (Throwable $e) {
            $ok = false;
            $report['mysql_gps'] = 'FAILED: '.$e->getMessage();
            if (! $this->option('json')) {
                $this->error('mysql_gps FAILED: '.$e->getMessage());
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
