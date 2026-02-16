<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Renames labour_* attendance tables to attendance_* and changes labour_id to user_id.
     */
    public function up(): void
    {
        $this->migrateTable('labour_gps_data', 'attendance_gps_data', [
            'drop_indexes' => [
                'worker_gps_data_labour_id_date_time_index',
                'worker_gps_data_date_time_index',
                'labour_gps_data_labour_id_date_time_index',
                'labour_gps_data_date_time_index',
            ],
        ]);
        $this->migrateTable('labour_attendance_sessions', 'attendance_sessions', [
            'drop_unique' => 'unique_labour_date_session',
            'drop_unique_alt' => 'unique_worker_date_session',
            'add_unique' => ['columns' => ['user_id', 'date'], 'name' => 'unique_user_date_session'],
        ]);
        $this->migrateTable('labour_daily_reports', 'attendance_daily_reports', [
            'drop_unique' => 'unique_labour_date_report',
            'drop_unique_alt' => 'unique_worker_date_report',
            'add_unique' => ['columns' => ['user_id', 'date'], 'name' => 'unique_user_date_report'],
        ]);
        $this->migrateTable('labour_monthly_payrolls', 'attendance_monthly_payrolls', [
            'drop_unique' => 'unique_labour_month_year',
            'drop_unique_alt' => 'unique_worker_month_year',
            'add_unique' => ['columns' => ['user_id', 'month', 'year'], 'name' => 'unique_user_month_year'],
        ]);
        $this->migrateTable('labour_shift_schedules', 'attendance_shift_schedules', [
            'drop_unique' => 'unique_labour_shift_date',
            'drop_unique_alt' => 'unique_worker_shift_date',
            'add_unique' => ['columns' => ['user_id', 'shift_id', 'scheduled_date'], 'name' => 'unique_user_shift_date'],
        ]);
    }

    private function dropForeignKeyIfExists(string $table, string $column): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            $schema = config('database.connections.mysql.database');
            $foreignKeys = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL",
                [$schema, $table, $column]
            );
            foreach ($foreignKeys as $fk) {
                try {
                    DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                } catch (\Exception $e) {
                    // Ignore if already dropped
                }
            }
        } else {
            try {
                Schema::table($table, function (Blueprint $blueprint) use ($column) {
                    $blueprint->dropForeign([$column]);
                });
            } catch (\Exception $e) {
                // Ignore if doesn't exist
            }
        }
    }

    private function migrateTable(string $oldTable, string $newTable, array $options): void
    {
        if (! Schema::hasTable($oldTable)) {
            return;
        }

        $this->dropForeignKeyIfExists($oldTable, 'labour_id');

        $driver = Schema::getConnection()->getDriverName();

        $dropUniqueNames = array_filter([
            $options['drop_unique'] ?? null,
            $options['drop_unique_alt'] ?? null,
        ]);
        foreach ($dropUniqueNames as $indexName) {
            try {
                if ($driver === 'sqlite') {
                    DB::statement("DROP INDEX IF EXISTS \"{$indexName}\"");
                } else {
                    DB::statement("ALTER TABLE `{$oldTable}` DROP INDEX `{$indexName}`");
                }
            } catch (\Exception $e) {
                // Index might have different name or already dropped
            }
        }
        if ($driver === 'sqlite') {
            $indexes = DB::select("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name=? AND sql IS NOT NULL", [$oldTable]);
            foreach ($indexes as $idx) {
                if ($idx->name !== 'primary' && stripos($idx->name, 'primary') === false) {
                    try {
                        DB::statement("DROP INDEX IF EXISTS \"{$idx->name}\"");
                    } catch (\Exception $e) {
                        //
                    }
                }
            }
        } else {
            foreach ($options['drop_indexes'] ?? [] as $indexName) {
                try {
                    DB::statement("ALTER TABLE `{$oldTable}` DROP INDEX `{$indexName}`");
                } catch (\Exception $e) {
                    //
                }
            }
        }

        if (! Schema::hasColumn($oldTable, 'user_id')) {
            Schema::table($oldTable, function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
            });
        }

        if (Schema::hasColumn($oldTable, 'labour_id')) {
            DB::table($oldTable)->orderBy('id')->chunk(100, function ($rows) use ($oldTable) {
                foreach ($rows as $row) {
                    $labour = DB::table('labours')->where('id', $row->labour_id)->first();
                    if ($labour && $labour->user_id) {
                        DB::table($oldTable)->where('id', $row->id)->update(['user_id' => $labour->user_id]);
                    }
                }
            });

            DB::table($oldTable)->whereNull('user_id')->delete();
        }

        if (Schema::hasColumn($oldTable, 'labour_id')) {
            Schema::table($oldTable, function (Blueprint $table) {
                $table->dropColumn('labour_id');
            });
        }

        Schema::table($oldTable, function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        if (! empty($options['add_unique'])) {
            $cols = implode(', ', $options['add_unique']['columns']);
            $name = $options['add_unique']['name'];
            if ($driver === 'sqlite') {
                DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS \"{$name}\" ON \"{$oldTable}\" ({$cols})");
            } else {
                DB::statement("ALTER TABLE `{$oldTable}` ADD UNIQUE KEY `{$name}` ({$cols})");
            }
        }

        if ($newTable === 'attendance_gps_data') {
            Schema::table($oldTable, function (Blueprint $table) {
                $table->index(['user_id', 'date_time']);
                $table->index('date_time');
            });
        }

        Schema::rename($oldTable, $newTable);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'attendance_gps_data' => 'labour_gps_data',
            'attendance_sessions' => 'labour_attendance_sessions',
            'attendance_daily_reports' => 'labour_daily_reports',
            'attendance_monthly_payrolls' => 'labour_monthly_payrolls',
            'attendance_shift_schedules' => 'labour_shift_schedules',
        ];

        foreach ($tables as $newTable => $oldTable) {
            if (! Schema::hasTable($newTable)) {
                continue;
            }

            Schema::table($newTable, function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });

            Schema::table($newTable, function (Blueprint $table) {
                $table->unsignedBigInteger('labour_id')->nullable()->after('id');
            });

            DB::table($newTable)->orderBy('id')->chunk(100, function ($rows) use ($newTable) {
                foreach ($rows as $row) {
                    $labour = DB::table('labours')->where('user_id', $row->user_id)->first();
                    if ($labour) {
                        DB::table($newTable)->where('id', $row->id)->update(['labour_id' => $labour->id]);
                    }
                }
            });

            DB::table($newTable)->whereNull('labour_id')->delete();

            Schema::table($newTable, function (Blueprint $table) {
                $table->dropColumn('user_id');
                $table->unsignedBigInteger('labour_id')->nullable(false)->change();
                $table->foreign('labour_id')->references('id')->on('labours')->cascadeOnDelete();
            });

            Schema::rename($newTable, $oldTable);
        }

        if (Schema::hasTable('labour_attendance_sessions')) {
            try {
                DB::statement('ALTER TABLE labour_attendance_sessions ADD UNIQUE KEY unique_labour_date_session (labour_id, date)');
            } catch (\Exception $e) {
                //
            }
        }
        if (Schema::hasTable('labour_daily_reports')) {
            try {
                DB::statement('ALTER TABLE labour_daily_reports ADD UNIQUE KEY unique_labour_date_report (labour_id, date)');
            } catch (\Exception $e) {
                //
            }
        }
        if (Schema::hasTable('labour_monthly_payrolls')) {
            try {
                DB::statement('ALTER TABLE labour_monthly_payrolls ADD UNIQUE KEY unique_labour_month_year (labour_id, month, year)');
            } catch (\Exception $e) {
                //
            }
        }
        if (Schema::hasTable('labour_shift_schedules')) {
            try {
                DB::statement('ALTER TABLE labour_shift_schedules ADD UNIQUE KEY unique_labour_shift_date (labour_id, shift_id, scheduled_date)');
            } catch (\Exception $e) {
                //
            }
        }
    }
};
