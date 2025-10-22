<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('gps_data', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement();
            $table->unsignedBigInteger('device_id');
            $table->json('coordinate');
            $table->unsignedInteger('speed');
            $table->unsignedTinyInteger('status');
            $table->json('directions');
            $table->string('imei', 20);
            $table->dateTime('date_time');

            // Add indexes for better query performance
            $table->index('device_id');
            $table->index('imei');
            $table->index(['device_id', 'date_time']);
        });

        // For partitioning to work, the partitioning column (date_time) must be part of the primary key
        // We need to alter the table to set a composite primary key
        DB::statement('ALTER TABLE gps_data DROP PRIMARY KEY, ADD PRIMARY KEY (id, date_time)');

        // Implement daily table partitioning
        // MySQL requires the partitioning column to be part of a unique key
        // Create initial partitions for the next 7 days
        $partitions = [];
        $startDate = now();

        for ($i = 0; $i < 7; $i++) {
            $date = $startDate->copy()->addDays($i);
            $partitionName = 'p' . $date->format('Ymd');
            $partitionValue = $date->copy()->addDay()->format('Ymd');
            $partitions[] = "PARTITION {$partitionName} VALUES LESS THAN ({$partitionValue})";
        }

        $partitionsStr = implode(",\n                ", $partitions);

        DB::statement("
            ALTER TABLE gps_data
            PARTITION BY RANGE (YEAR(date_time) * 10000 + MONTH(date_time) * 100 + DAY(date_time)) (
                {$partitionsStr},
                PARTITION p_future VALUES LESS THAN MAXVALUE
            )
        ");

        // Enable MySQL event scheduler if not already enabled
        DB::statement("SET GLOBAL event_scheduler = ON");

        // Create MySQL event to automatically create new partitions daily at midnight
        DB::statement("
            CREATE EVENT IF NOT EXISTS create_daily_gps_data_partitions
            ON SCHEDULE EVERY 1 DAY
            STARTS (TIMESTAMP(CURRENT_DATE) + INTERVAL 1 DAY)
            DO
            BEGIN
                DECLARE partition_name VARCHAR(20);
                DECLARE partition_date DATE;
                DECLARE partition_value INT;
                DECLARE next_partition_value INT;

                -- Calculate partition for the next day
                SET partition_date = DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY);
                SET partition_name = CONCAT('p', DATE_FORMAT(partition_date, '%Y%m%d'));
                SET partition_value = YEAR(partition_date) * 10000 + MONTH(partition_date) * 100 + DAY(partition_date);
                SET next_partition_value = YEAR(DATE_ADD(partition_date, INTERVAL 1 DAY)) * 10000 +
                                         MONTH(DATE_ADD(partition_date, INTERVAL 1 DAY)) * 100 +
                                         DAY(DATE_ADD(partition_date, INTERVAL 1 DAY));

                -- Check if partition already exists
                IF NOT EXISTS (
                    SELECT 1
                    FROM INFORMATION_SCHEMA.PARTITIONS
                    WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'gps_data'
                    AND PARTITION_NAME = partition_name
                ) THEN
                    -- Reorganize the p_future partition to add the new partition
                    SET @sql = CONCAT('ALTER TABLE gps_data REORGANIZE PARTITION p_future INTO (',
                                     'PARTITION ', partition_name, ' VALUES LESS THAN (', next_partition_value, '),',
                                     'PARTITION p_future VALUES LESS THAN MAXVALUE)');
                    PREPARE stmt FROM @sql;
                    EXECUTE stmt;
                    DEALLOCATE PREPARE stmt;
                END IF;
            END
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the MySQL event first
        DB::statement("DROP EVENT IF EXISTS create_daily_gps_data_partitions");

        // Then drop the table
        Schema::dropIfExists('gps_data');
    }
};
