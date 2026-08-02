<?php

namespace App\Console\Commands;

use App\Jobs\IngestGpsData;
use App\Models\GpsDevice;
use App\Models\Tractor;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PDO;
use Throwable;

/**
 * End-to-end diagnosis for "live WS works but gps_data is not written".
 */
class GpsDiagnosePersistCommand extends Command
{
    protected $signature = 'gps:diagnose-persist
                            {--tractor=38 : Tractor ID to sample}
                            {--imei= : Override IMEI (otherwise from tractor GPS device)}
                            {--smoke : Also run a real IngestGpsData write+verify+delete}
                            {--hours=6 : Look back N hours for recent gps_data rows}';

    protected $description = 'Diagnose why GPS logs are not persisting to mysql_gps.gps_data';

    private int $critical = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->info('=== GPS PERSIST DIAGNOSE ===');
        $this->line('time: '.now()->toDateTimeString().' tz='.config('app.timezone'));
        $this->newLine();

        $this->sectionRedis();
        $this->sectionEnv();
        $this->sectionCode();
        $this->sectionMysql();
        $this->sectionTractorSample();
        $this->sectionFailedJobs();
        $this->sectionRecentLogs();
        $this->sectionMysqlLocks();

        if ($this->option('smoke')) {
            $this->sectionSmoke();
        } else {
            $this->line('• Tip: re-run with --smoke to force a real write test');
        }

        $this->newLine();
        $this->info('=== SUMMARY ===');
        if ($this->critical > 0) {
            $this->error("CRITICAL={$this->critical} WARNING={$this->warnings}");
            $this->error('Fix CRITICAL items before replaying gateway traffic.');

            return self::FAILURE;
        }

        if ($this->warnings > 0) {
            $this->warn("No CRITICAL, but WARNING={$this->warnings}");
            $this->line('Persist path may work; review warnings before heavy replay.');

            return self::SUCCESS;
        }

        $this->info('No CRITICAL/WARNING — persist path looks healthy.');

        return self::SUCCESS;
    }

    private function crit(string $msg): void
    {
        $this->critical++;
        $this->error('✖ CRITICAL: '.$msg);
    }

    private function warnMsg(string $msg): void
    {
        $this->warnings++;
        $this->warn('⚠ WARNING: '.$msg);
    }

    private function ok(string $msg): void
    {
        $this->info('✔ '.$msg);
    }

    private function sectionRedis(): void
    {
        $this->comment('[1] Redis + queues');
        try {
            $pong = Redis::connection()->ping();
            $pongStr = is_object($pong) ? (string) $pong : (string) json_encode($pong);
            $lower = strtolower($pongStr);
            if (str_contains($lower, 'loading')) {
                $this->crit('Redis is LOADING — queue ingest cannot run');
            } else {
                $this->ok('Redis ping='.$pongStr);
            }

            foreach (['gps-processing', 'gps-broadcast', 'gps-side-effects', 'default'] as $q) {
                $len = (int) Redis::llen("queues:{$q}");
                $this->line("  • queues:{$q} length={$len}");
                if ($q === 'gps-processing' && $len > 5000) {
                    $this->warnMsg("gps-processing backlog high ({$len}) — workers stuck or too few");
                }
            }
        } catch (Throwable $e) {
            $this->crit('Redis FAILED: '.$e->getMessage());
        }
        $this->newLine();
    }

    private function sectionEnv(): void
    {
        $this->comment('[2] Env / driver');
        $driver = (string) config('services.gps_ingest.driver', 'laravel');
        $this->line("  • GPS_INGEST_DRIVER={$driver}");
        if ($driver === 'go') {
            $this->warnMsg('Driver=go — Laravel IngestGpsData may be bypassed; check Go gps-ingest service');
        } else {
            $this->ok('Driver=laravel (IngestGpsData path)');
        }

        $exempt = (string) env('GPS_REPORTS_RATE_LIMIT_EXEMPT_IPS', '');
        if (trim($exempt) === '') {
            $this->warnMsg('GPS_REPORTS_RATE_LIMIT_EXEMPT_IPS empty — gateway may get 403');
        } else {
            $this->ok('GPS_REPORTS_RATE_LIMIT_EXEMPT_IPS is set');
        }

        $queue = (string) config('queue.default');
        $this->line("  • QUEUE_CONNECTION={$queue}");
        if ($queue !== 'redis') {
            $this->warnMsg("QUEUE_CONNECTION={$queue} (expected redis for GPS workers)");
        }

        $dbGps = (string) config('database.connections.mysql_gps.database');
        $dbGpsHost = (string) config('database.connections.mysql_gps.host');
        $this->line("  • mysql_gps host={$dbGpsHost} database={$dbGps}");
        $persistent = config('database.connections.mysql_gps.options')[PDO::ATTR_PERSISTENT] ?? null;
        if ($persistent) {
            $this->warnMsg('mysql_gps ATTR_PERSISTENT=true — stale connections can break queue inserts');
        } else {
            $this->ok('mysql_gps ATTR_PERSISTENT is off/false');
        }
        $this->newLine();
    }

    private function sectionCode(): void
    {
        $this->comment('[3] Code gates');
        $path = app_path('Jobs/IngestGpsData.php');
        if (! File::exists($path)) {
            $this->crit('IngestGpsData.php missing');
        } else {
            $src = File::get($path);
            if (! str_contains($src, 'normalizeDeviceDateTime')) {
                $this->crit('IngestGpsData missing normalizeDeviceDateTime (stuck clocks break today path)');
            } else {
                $this->ok('normalizeDeviceDateTime present');
            }
            if (! str_contains($src, 'ensureGpsConnection') && ! str_contains($src, 'reconnect')) {
                $this->warnMsg('IngestGpsData may lack reconnect-on-stale-connection logic');
            } else {
                $this->ok('reconnect / ensureGpsConnection logic present');
            }
        }
        $this->newLine();
    }

    private function sectionMysql(): void
    {
        $this->comment('[4] mysql_gps / partitions / today');
        try {
            $db = (string) config('database.connections.mysql_gps.database');
            DB::connection('mysql_gps')->selectOne('SELECT 1 AS ok');
            $this->ok("mysql_gps connected ({$db})");

            $unique = DB::connection('mysql_gps')->selectOne('
                SELECT COUNT(*) AS c
                FROM information_schema.statistics
                WHERE table_schema = ? AND table_name = ? AND index_name = ?
            ', [$db, 'gps_data', 'gps_data_imei_date_time_unique']);
            $hasUnique = ((int) ($unique->c ?? 0)) > 0;
            $this->line('  • unique(imei,date_time): '.($hasUnique ? 'YES' : 'NO (plain insert)'));

            $future = DB::connection('mysql_gps')->selectOne('
                SELECT COUNT(*) AS c
                FROM information_schema.PARTITIONS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND PARTITION_NAME = ?
            ', [$db, 'gps_data', 'p_future']);
            if (((int) ($future->c ?? 0)) < 1) {
                $this->crit('Partition p_future missing — new-day inserts can fail');
            } else {
                $this->ok('Partition p_future present');
            }

            // Partition list (names only)
            $parts = DB::connection('mysql_gps')->select('
                SELECT PARTITION_NAME AS name, TABLE_ROWS AS rows_est
                FROM information_schema.PARTITIONS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                ORDER BY PARTITION_ORDINAL_POSITION
            ', [$db, 'gps_data']);
            foreach ($parts as $p) {
                if ($p->name) {
                    $this->line("  • partition {$p->name} approx_rows={$p->rows_est}");
                }
            }

            $today = DB::connection('mysql_gps')->selectOne('
                SELECT COUNT(*) AS c, MAX(date_time) AS max_dt
                FROM gps_data
                WHERE date_time >= CURDATE()
                  AND date_time < (CURDATE() + INTERVAL 2 DAY)
            ');
            $todayCount = (int) ($today->c ?? 0);
            $this->line('  • rows today (sane < +2d): '.$todayCount.' | max='.($today->max_dt ?? 'null'));
            if ($todayCount === 0) {
                $this->warnMsg('No sane GPS rows for today yet — ingest not landing or no traffic');
            }

            $hours = max(1, (int) $this->option('hours'));
            $recent = DB::connection('mysql_gps')->selectOne('
                SELECT COUNT(*) AS c, MAX(date_time) AS max_dt
                FROM gps_data
                WHERE date_time >= (NOW() - INTERVAL ? HOUR)
                  AND date_time < (NOW() + INTERVAL 1 DAY)
            ', [$hours]);
            $this->line("  • rows last {$hours}h (sane): ".(int) ($recent->c ?? 0).' | max='.($recent->max_dt ?? 'null'));
        } catch (Throwable $e) {
            $this->crit('mysql_gps FAILED: '.$e->getMessage());
        }
        $this->newLine();
    }

    private function sectionTractorSample(): void
    {
        $this->comment('[5] Tractor / IMEI binding');
        $tractorId = (int) $this->option('tractor');
        $imeiOpt = $this->option('imei');

        try {
            $tractor = Tractor::with('gpsDevice')->find($tractorId);
            if (! $tractor) {
                $this->warnMsg("Tractor {$tractorId} not found in app DB");
                $this->newLine();

                return;
            }

            $imei = $imeiOpt ?: (string) ($tractor->gpsDevice->imei ?? '');
            $this->line("  • tractor_id={$tractor->id} name=".($tractor->name ?? '-'));
            if ($imei === '') {
                $this->crit("Tractor {$tractorId} has no bound GPS IMEI — ingest discards batches");
                $this->newLine();

                return;
            }
            $this->ok("IMEI bound: {$imei}");

            $device = GpsDevice::where('imei', $imei)->first();
            if (! $device) {
                $this->crit("GpsDevice row missing for IMEI {$imei}");
            } elseif ((int) $device->tractor_id !== (int) $tractor->id) {
                $this->crit("IMEI {$imei} bound to tractor_id={$device->tractor_id}, not {$tractor->id}");
            }

            $row = DB::connection('mysql_gps')->selectOne('
                SELECT COUNT(*) AS c, MIN(date_time) AS min_dt, MAX(date_time) AS max_dt
                FROM gps_data
                WHERE tractor_id = ?
            ', [$tractorId]);
            $this->line(sprintf(
                '  • gps_data lifetime for tractor: count=%d min=%s max=%s',
                (int) ($row->c ?? 0),
                $row->min_dt ?? 'null',
                $row->max_dt ?? 'null'
            ));

            $today = DB::connection('mysql_gps')->selectOne('
                SELECT COUNT(*) AS c, MAX(date_time) AS max_dt
                FROM gps_data
                WHERE tractor_id = ?
                  AND date_time >= CURDATE()
                  AND date_time < (CURDATE() + INTERVAL 2 DAY)
            ', [$tractorId]);
            $tc = (int) ($today->c ?? 0);
            $this->line('  • gps_data today for tractor: '.$tc.' | max='.($today->max_dt ?? 'null'));
            if ($tc === 0) {
                $this->warnMsg("Tractor {$tractorId} has 0 rows today — path will be empty until persist works");
            }
        } catch (Throwable $e) {
            $this->crit('Tractor sample FAILED: '.$e->getMessage());
        }
        $this->newLine();
    }

    private function sectionFailedJobs(): void
    {
        $this->comment('[6] Failed queue jobs (IngestGpsData)');
        try {
            if (! DB::getSchemaBuilder()->hasTable('failed_jobs')) {
                $this->line('  • failed_jobs table missing — skip');
                $this->newLine();

                return;
            }

            $rows = DB::table('failed_jobs')
                ->where('payload', 'like', '%IngestGpsData%')
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'failed_at', 'exception']);

            if ($rows->isEmpty()) {
                $this->ok('No recent failed_jobs for IngestGpsData');
            } else {
                $this->warnMsg('Found failed IngestGpsData jobs — showing last '.$rows->count());
                foreach ($rows as $r) {
                    $ex = str_replace("\n", ' ', (string) $r->exception);
                    $ex = mb_substr($ex, 0, 180);
                    $this->line("  • id={$r->id} at={$r->failed_at} ex={$ex}");
                }
            }
        } catch (Throwable $e) {
            $this->warnMsg('failed_jobs query failed: '.$e->getMessage());
        }
        $this->newLine();
    }

    private function sectionRecentLogs(): void
    {
        $this->comment('[7] Recent log signals');
        $logDir = storage_path('logs');
        $files = [
            $logDir.'/laravel.log',
            $logDir.'/gps-processing.log',
        ];

        $patterns = [
            'IngestGpsData: persisted 0',
            'IngestGpsData: write left no durable',
            'IngestGpsData: bulk chunk insert failed',
            'IngestGpsData: stale MySQL',
            'IngestGpsData: partition',
            'MySQL server has gone away',
            'Unbound IMEI',
            'Redis.*LOADING',
            'gps:persist',
        ];

        $foundAny = false;
        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }
            $size = filesize($file);
            $this->line('  • scanning '.basename($file).' ('.round($size / 1048576, 1).' MB)');
            // Read last ~256KB only
            $fh = fopen($file, 'rb');
            if ($fh === false) {
                continue;
            }
            $read = 262144;
            if ($size > $read) {
                fseek($fh, -$read, SEEK_END);
            }
            $tail = stream_get_contents($fh) ?: '';
            fclose($fh);

            foreach ($patterns as $pat) {
                if (preg_match('/'.$pat.'/i', $tail)) {
                    $foundAny = true;
                    $this->warnMsg('Log hit in '.basename($file).": /{$pat}/i");
                }
            }
        }

        if (! $foundAny) {
            $this->ok('No matching error patterns in recent log tails');
        }
        $this->newLine();
    }

    private function sectionMysqlLocks(): void
    {
        $this->comment('[7b] MySQL locks / long queries (common hang on gps_data)');
        try {
            $rows = DB::connection('mysql_gps')->select('
                SELECT id, user, db, command, time, state,
                       LEFT(IFNULL(info, ""), 120) AS info
                FROM information_schema.processlist
                WHERE command != "Sleep"
                  AND time >= 2
                ORDER BY time DESC
                LIMIT 15
            ');
            if ($rows === []) {
                $this->ok('No long-running MySQL queries (>=2s)');
            } else {
                $this->warnMsg('Long-running MySQL queries present — INSERT into gps_data may wait on locks');
                foreach ($rows as $r) {
                    $this->line(sprintf(
                        '  • id=%s user=%s time=%ss state=%s info=%s',
                        $r->id,
                        $r->user,
                        $r->time,
                        $r->state,
                        $r->info
                    ));
                }
            }
        } catch (Throwable $e) {
            $this->warnMsg('processlist check failed: '.$e->getMessage());
        }
        $this->newLine();
    }

    private function sectionSmoke(): void
    {
        $this->comment('[8] Live write smoke (direct INSERT, then IngestGpsData)');
        $this->line('  • If this step hangs >10s: gps_data is likely locked (ALTER/REORGANIZE p_future).');
        $this->line('  • Ctrl+C and run: SHOW FULL PROCESSLIST;');

        $tractorId = (int) $this->option('tractor');
        $imeiOpt = $this->option('imei');

        try {
            $tractor = Tractor::with('gpsDevice')->find($tractorId);
            $imei = $imeiOpt ?: (string) ($tractor?->gpsDevice?->imei ?? '');
            if (! $tractor || $imei === '') {
                $device = GpsDevice::query()->whereNotNull('tractor_id')->where('imei', '!=', '')->first();
                $tractor = $device?->tractor;
                $imei = (string) ($device?->imei ?? '');
            }
            if (! $tractor || $imei === '') {
                $this->crit('Smoke skipped — no tractor/IMEI available');

                return;
            }

            $gps = DB::connection('mysql_gps');
            try {
                $gps->statement('SET SESSION innodb_lock_wait_timeout = 5');
                $gps->statement('SET SESSION lock_wait_timeout = 5');
            } catch (Throwable) {
                // ignore if unsupported
            }

            $stamp = Carbon::now()->format('Y-m-d H:i:s');
            $direct = [
                'tractor_id' => $tractor->id,
                'coordinate' => json_encode([35.6892, 51.3890]),
                'speed' => 5,
                'status' => 1,
                'directions' => json_encode(['ew' => 1, 'ns' => 1]),
                'imei' => $imei,
                'date_time' => $stamp,
            ];

            $this->line("  • [8a] direct INSERT tractor={$tractor->id} imei={$imei} at {$stamp} ...");
            $t0 = microtime(true);
            try {
                $gps->table('gps_data')->insert($direct);
                $ms = (int) ((microtime(true) - $t0) * 1000);
                $this->ok("direct INSERT ok in {$ms}ms");
            } catch (Throwable $e) {
                $ms = (int) ((microtime(true) - $t0) * 1000);
                $msg = $e->getMessage();
                if (str_contains(strtolower($msg), 'lock wait') || str_contains(strtolower($msg), '1205')) {
                    $this->crit("direct INSERT lock-wait after {$ms}ms — gps_data is locked (often REORGANIZE p_future)");
                    $this->line('  → Kill long ALTER/OPTIMIZE on gps_data, or wait until it finishes');
                    $this->line('  → SHOW FULL PROCESSLIST; SHOW ENGINE INNODB STATUS\\G');

                    return;
                }
                if (str_contains(strtolower($msg), 'partition')) {
                    $this->crit("direct INSERT partition error after {$ms}ms: {$msg}");

                    return;
                }
                $this->crit("direct INSERT failed after {$ms}ms: {$msg}");

                return;
            }

            $row = $gps->table('gps_data')
                ->where('imei', $imei)
                ->where('tractor_id', $tractor->id)
                ->where('date_time', $stamp)
                ->first();

            if (! $row) {
                $this->crit('direct INSERT reported ok but row not readable afterward');

                return;
            }

            // cleanup direct row before job smoke (avoid unique collision if index exists)
            $gps->table('gps_data')->where('id', $row->id)->where('date_time', $stamp)->delete();
            $this->line('  • direct row deleted');

            $stamp2 = Carbon::now()->format('Y-m-d H:i:s');
            $payload = [[
                'coordinate' => [35.6892, 51.3890],
                'speed' => 5,
                'status' => 1,
                'directions' => ['ew' => 1, 'ns' => 1],
                'date_time' => $stamp2,
                'imei' => $imei,
            ]];

            $this->line('  • [8b] IngestGpsData::handle() ...');
            $t1 = microtime(true);
            // Disable NOC HTTP side-channel for smoke (can add latency).
            config(['services.noc_monitor.enabled' => false]);
            Queue::fake();
            (new IngestGpsData($payload, 'diagnose-'.uniqid()))->handle();
            $ms2 = (int) ((microtime(true) - $t1) * 1000);

            $row2 = $gps->table('gps_data')
                ->where('imei', $imei)
                ->where('tractor_id', $tractor->id)
                ->where('date_time', $stamp2)
                ->first();

            if (! $row2) {
                $this->crit("IngestGpsData smoke FAIL after {$ms2}ms — job ran but row missing");
            } else {
                $this->ok("IngestGpsData smoke PASS in {$ms2}ms — row id={$row2->id}");
                $gps->table('gps_data')->where('id', $row2->id)->where('date_time', $stamp2)->delete();
                $this->line('  • ingest smoke row deleted');
            }
        } catch (Throwable $e) {
            $this->crit('SMOKE EXCEPTION: '.$e->getMessage());
        }
        $this->newLine();
    }
}
