<?php

namespace App\Console\Commands;

use App\Models\GpsMetricsCalculation;
use App\Models\Tractor;
use App\Models\TractorTask;
use App\Models\VolkOilSpray;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;

class DebugSchedulerQueueHealth extends Command
{
    protected $signature = 'app:debug-scheduler-queue-health
                            {--output= : Write JSON report to this path}
                            {--timed-schedule : Run schedule:run and record duration}';

    protected $description = 'Capture scheduler/queue health metrics (Phases 0–2 of incident debugging plan)';

    public function handle(): int
    {
        $report = [
            'timestamp' => now()->toIso8601String(),
            'environment' => [
                'app_env' => config('app.env'),
                'queue_connection' => config('queue.default'),
                'cache_driver' => config('cache.default'),
                'broadcast_driver' => config('broadcasting.default'),
            ],
            'warnings' => [],
            'processes' => $this->processCounts(),
            'redis' => $this->redisSnapshot(),
            'database' => $this->databaseSnapshot(),
            'scheduler' => $this->schedulerAudit(),
            'queue_workers' => $this->workerConfigAudit(),
        ];

        if ($this->option('timed-schedule')) {
            $report['schedule_run'] = $this->timedScheduleRun();
        }

        $this->printReport($report);

        $outputPath = $this->option('output');
        if ($outputPath) {
            File::put($outputPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Report written to {$outputPath}");
        }

        return Command::SUCCESS;
    }

    private function processCounts(): array
    {
        return [
            'schedule_run' => $this->countMatchingProcesses('schedule:run'),
            'queue_work' => $this->countMatchingProcesses('queue:work'),
        ];
    }

    private function countMatchingProcesses(string $needle): int
    {
        $count = 0;

        if (! function_exists('exec')) {
            return $count;
        }

        $output = [];
        exec('ps aux 2>/dev/null', $output);

        foreach ($output as $line) {
            if (str_contains($line, 'artisan') && str_contains($line, $needle)) {
                $count++;
            }
        }

        return $count;
    }

    private function redisSnapshot(): array
    {
        $snapshot = [
            'available' => false,
            'queues' => [],
        ];

        try {
            $connection = config('queue.connections.redis.connection', 'default');
            Redis::connection($connection)->ping();
            $snapshot['available'] = true;
            $snapshot['queues'] = [
                'default' => (int) Redis::connection($connection)->llen('queues:default'),
                'gps-processing' => (int) Redis::connection($connection)->llen('queues:gps-processing'),
            ];
            $snapshot['memory_used_human'] = Redis::connection($connection)->info()['Memory']['used_memory_human'] ?? null;
        } catch (\Throwable $e) {
            $snapshot['error'] = $e->getMessage();
        }

        return $snapshot;
    }

    private function databaseSnapshot(): array
    {
        $snapshot = [
            'failed_jobs_last_hour' => null,
            'tractor_tasks_ended_today' => null,
            'volk_oil_sprays_historical_dispatch_risk' => null,
            'volk_oil_sprays_pending_check' => null,
            'tractors_count' => null,
            'gps_data_indexes' => null,
        ];

        try {
            if (DB::getSchemaBuilder()->hasTable('failed_jobs')) {
                $snapshot['failed_jobs_last_hour'] = DB::table('failed_jobs')
                    ->where('failed_at', '>=', now()->subHour())
                    ->count();
            }

            if (DB::getSchemaBuilder()->hasTable('tractor_tasks')) {
                $today = now()->toDateString();
                $snapshot['tractor_tasks_ended_today'] = TractorTask::query()
                    ->where('date', $today)
                    ->whereIn('status', ['in_progress', 'stopped'])
                    ->where('end_time', '<=', now()->format('H:i:s'))
                    ->count();
            }

            if (DB::getSchemaBuilder()->hasTable('volk_oil_sprays')) {
                $snapshot['volk_oil_sprays_historical_dispatch_risk'] = VolkOilSpray::query()
                    ->where('end_dt', '<', today())
                    ->count();

                if (DB::getSchemaBuilder()->hasColumn('volk_oil_sprays', 'cold_requirement_checked_at')) {
                    $snapshot['volk_oil_sprays_pending_check'] = VolkOilSpray::query()
                        ->whereDate('end_dt', today()->subDay())
                        ->whereNull('cold_requirement_checked_at')
                        ->count();
                }
            }

            $snapshot['tractors_count'] = Tractor::count();

            if (DB::getSchemaBuilder()->hasTable('gps_data')) {
                $indexes = DB::select('SHOW INDEX FROM gps_data');
                $snapshot['gps_data_indexes'] = collect($indexes)
                    ->pluck('Key_name')
                    ->unique()
                    ->values()
                    ->all();
            }
        } catch (\Throwable $e) {
            $snapshot['error'] = $e->getMessage();
        }

        return $snapshot;
    }

    private function schedulerAudit(): array
    {
        $mutexProtected = [
            'app:change-farm-plan-status',
            'app:change-irrigation-status',
            'tractor:check-stoppage-warnings',
            'tractor:check-activity-status',
            'tractor:update-ended-tasks',
        ];

        $missingMutex = [];
        foreach ($mutexProtected as $command) {
            $kernelFile = file_get_contents(app_path('Console/Kernel.php'));
            if (! preg_match('/command\(\''.$command.'\'\).*withoutOverlapping/s', $kernelFile)) {
                $missingMutex[] = $command;
            }
        }

        return [
            'scheduled_task_count' => 19,
            'commands_missing_withoutOverlapping_in_kernel' => $missingMutex,
            'gps_worker_timeout_seconds' => $this->supervisorTimeout('laravel-gps-workers.conf'),
            'process_gps_job_timeout_seconds' => 60,
        ];
    }

    private function workerConfigAudit(): array
    {
        return [
            'default_workers' => $this->supervisorNumProcs('laravel-worker.conf'),
            'gps_workers' => $this->supervisorNumProcs('laravel-gps-workers.conf'),
            'notification_workers' => $this->supervisorNumProcs('laravel-notifications-worker.conf'),
            'queue_connection_expected' => 'redis',
            'queue_connection_actual' => config('queue.default'),
            'sync_queue_warning' => config('queue.default') === 'sync',
        ];
    }

    private function supervisorTimeout(string $filename): ?int
    {
        $path = base_path('deploy/supervisor/'.$filename);
        if (! File::exists($path)) {
            return null;
        }

        if (preg_match('/--timeout=(\d+)/', File::get($path), $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function supervisorNumProcs(string $filename): ?int
    {
        $path = base_path('deploy/supervisor/'.$filename);
        if (! File::exists($path)) {
            return null;
        }

        if (preg_match('/numprocs=(\d+)/', File::get($path), $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function timedScheduleRun(): array
    {
        $start = microtime(true);
        $exitCode = $this->call('schedule:run', ['--verbose' => true]);
        $duration = round(microtime(true) - $start, 2);

        return [
            'duration_seconds' => $duration,
            'exit_code' => $exitCode,
            'overlap_risk' => $duration > 60,
        ];
    }

    private function printReport(array $report): void
    {
        $this->line('=== Scheduler / Queue Health ===');
        $this->table(
            ['Key', 'Value'],
            [
                ['Queue', $report['environment']['queue_connection']],
                ['Cache', $report['environment']['cache_driver']],
                ['Broadcast', $report['environment']['broadcast_driver']],
                ['schedule:run processes', $report['processes']['schedule_run']],
                ['queue:work processes', $report['processes']['queue_work']],
                ['Redis default queue', $report['redis']['queues']['default'] ?? 'n/a'],
                ['Redis gps-processing', $report['redis']['queues']['gps-processing'] ?? 'n/a'],
                ['Failed jobs (1h)', $report['database']['failed_jobs_last_hour'] ?? 'n/a'],
                ['Tractor tasks ending today', $report['database']['tractor_tasks_ended_today'] ?? 'n/a'],
                ['VolkOilSpray historical rows', $report['database']['volk_oil_sprays_historical_dispatch_risk'] ?? 'n/a'],
                ['Tractors', $report['database']['tractors_count'] ?? 'n/a'],
            ]
        );

        if ($report['queue_workers']['sync_queue_warning'] ?? false) {
            $this->warn('QUEUE_CONNECTION=sync — jobs run inline inside schedule:run / HTTP requests.');
        }

        if (isset($report['schedule_run'])) {
            $this->line("schedule:run duration: {$report['schedule_run']['duration_seconds']}s");
            if ($report['schedule_run']['overlap_risk']) {
                $this->warn('schedule:run > 60s — overlap risk on next cron tick.');
            }
        }
    }
}
