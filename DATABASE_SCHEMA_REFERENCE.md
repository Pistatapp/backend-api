# Database Schema Reference

Generated: 2026-08-04T21:50:44+03:30
Database: api_db
Engine default: InnoDB
Charset/Collation: utf8mb4 / utf8mb4_unicode_ci

## Table Inventory (Production Snapshot)

| Table | Engine | Rows (approx) | Data | Index | Notes |
|---|---|---:|---:|---:|---|
| gps_data | InnoDB | 14,734,377 | 1506 MB | 2188 MB | **96% of DB size**; RANGE partitioned |
| notifications | InnoDB | 39,514 | 55 MB | 6 MB | |
| telescope_entries | InnoDB | 129,425 | 55 MB | 33 MB | Pruned daily (24h) |
| trees | InnoDB | 2,200 | 8 MB | 0.2 MB | |
| telescope_entries_tags | InnoDB | 9,818 | 1 MB | 4 MB | |
| gps_metrics_calculations | InnoDB | 2,465 | 0.3 MB | 0.2 MB | Daily tractor metrics |
| attendance_gps_data | InnoDB | 2,595 | 0.25 MB | 0.17 MB | Labour phone GPS |
| irrigation_valve | InnoDB | 3,228 | 0.4 MB | 0.2 MB | |
| irrigation_plot | InnoDB | 3,038 | 0.16 MB | 0.2 MB | |
| attendance_daily_reports | InnoDB | 1,790 | 0.16 MB | 0.2 MB | |
| farm_reports | InnoDB | 901 | 0.14 MB | 0.2 MB | |
| irrigations | InnoDB | 1,026 | 0.13 MB | 0.2 MB | |
| *… 62 smaller tables …* | InnoDB | — | < 0.2 MB each | — | See SHOW CREATE below |

**Total:** 74 base tables, ~3.77 GB

### gps_data Partitions

| Partition | Rows (approx) | Data |
|---|---:|---:|
| p20251022 – p20251028 | 7 daily partitions | ~1.5 MB each |
| p_future | 14,666,794 | 1497 MB |

> **Warning:** Nearly all rows live in `p_future`. Daily partition REORGANIZE was disabled after production incidents (metadata locks). See `MIGRATION_RISKS.md`.

### gps_data Daily Ingest (last 14 days)

| Date | Rows |
|---|---:|
| 2026-07-21 | 3,587 |
| 2026-07-22 | 6,539 |
| 2026-07-23 | 4,347 |
| 2026-07-24 | 5,300 |
| 2026-07-25 | 5,928 |
| 2026-07-26 | 6,200 |
| 2026-07-27 | 3,329 |
| 2026-07-28 | 4,525 |
| 2026-07-30 | 3,801 |
| 2026-08-01 | 9,665 |
| 2026-08-02 | 8,441 |
| 2026-08-03 | 4,482 |
| 2026-08-04 | 5,461 |

**Approximate daily rate:** 4,000–10,000 rows/day (~5–7 MB/day data+index growth)  
**Total gps_data rows:** 18,065,875 (includes far-future junk device clocks)

---



## Table: `app_releases`

```sql
CREATE TABLE `app_releases` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(255) NOT NULL,
  `release_notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `file_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `app_releases_version_unique` (`version`),
  KEY `app_releases_created_by_foreign` (`created_by`),
  KEY `app_releases_published_at_index` (`published_at`),
  CONSTRAINT `app_releases_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `attachments`

```sql
CREATE TABLE `attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `attachable_type` varchar(255) NOT NULL,
  `attachable_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attachments_user_id_foreign` (`user_id`),
  KEY `attachments_attachable_type_attachable_id_index` (`attachable_type`,`attachable_id`),
  CONSTRAINT `attachments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `attendance_daily_reports`

```sql
CREATE TABLE `attendance_daily_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `date` date NOT NULL,
  `scheduled_hours` decimal(5,2) NOT NULL DEFAULT 0.00,
  `actual_work_hours` decimal(5,2) NOT NULL DEFAULT 0.00,
  `overtime_hours` decimal(5,2) NOT NULL DEFAULT 0.00,
  `time_outside_zone` int(11) NOT NULL DEFAULT 0,
  `productivity_score` decimal(5,2) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_added_hours` decimal(5,2) NOT NULL DEFAULT 0.00,
  `admin_reduced_hours` decimal(5,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_date_report` (`user_id`,`date`),
  KEY `worker_daily_reports_approved_by_foreign` (`approved_by`),
  CONSTRAINT `labour_daily_reports_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `worker_daily_reports_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2047 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `attendance_gps_data`

```sql
CREATE TABLE `attendance_gps_data` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `coordinate` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`coordinate`)),
  `speed` decimal(8,2) DEFAULT NULL,
  `date_time` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `labour_gps_data_user_id_date_time_index` (`user_id`,`date_time`),
  KEY `labour_gps_data_date_time_index` (`date_time`),
  CONSTRAINT `labour_gps_data_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2684 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `attendance_monthly_payrolls`

```sql
CREATE TABLE `attendance_monthly_payrolls` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `month` tinyint(4) NOT NULL,
  `year` smallint(6) NOT NULL,
  `total_work_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `total_required_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `total_overtime_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `base_wage_total` bigint(20) NOT NULL DEFAULT 0,
  `overtime_wage_total` bigint(20) NOT NULL DEFAULT 0,
  `additions` bigint(20) NOT NULL DEFAULT 0,
  `deductions` bigint(20) NOT NULL DEFAULT 0,
  `final_total` bigint(20) NOT NULL DEFAULT 0,
  `generated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_month_year` (`user_id`,`month`,`year`),
  CONSTRAINT `labour_monthly_payrolls_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `attendance_sessions`

```sql
CREATE TABLE `attendance_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `date` date NOT NULL,
  `entry_time` datetime DEFAULT NULL,
  `exit_time` datetime DEFAULT NULL,
  `in_zone_duration` int(11) NOT NULL DEFAULT 0,
  `outside_zone_duration` int(11) NOT NULL DEFAULT 0,
  `efficiency` decimal(8,2) DEFAULT NULL,
  `status` enum('pending','in_progress','completed') NOT NULL DEFAULT 'in_progress',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_date_session` (`user_id`,`date`),
  CONSTRAINT `labour_attendance_sessions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `attendance_shift_schedules`

```sql
CREATE TABLE `attendance_shift_schedules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `shift_id` bigint(20) unsigned NOT NULL,
  `scheduled_date` date NOT NULL,
  `status` enum('scheduled','completed','missed','cancelled') NOT NULL DEFAULT 'scheduled',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_shift_date` (`user_id`,`shift_id`,`scheduled_date`),
  KEY `worker_shift_schedules_shift_id_foreign` (`shift_id`),
  CONSTRAINT `labour_shift_schedules_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `worker_shift_schedules_shift_id_foreign` FOREIGN KEY (`shift_id`) REFERENCES `work_shifts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `attendance_trackings`

```sql
CREATE TABLE `attendance_trackings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `farm_id` bigint(20) unsigned NOT NULL,
  `work_type` varchar(255) NOT NULL,
  `work_days` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`work_days`)),
  `work_hours` decimal(5,2) DEFAULT NULL,
  `start_work_time` time DEFAULT NULL,
  `end_work_time` time DEFAULT NULL,
  `hourly_wage` int(11) NOT NULL,
  `overtime_hourly_wage` int(11) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attendance_trackings_user_id_unique` (`user_id`),
  KEY `attendance_trackings_farm_id_foreign` (`farm_id`),
  CONSTRAINT `attendance_trackings_farm_id_foreign` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_trackings_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `crops`

```sql
CREATE TABLE `crops` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `cold_requirement` int(11) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `crops_created_by_index` (`created_by`),
  CONSTRAINT `crops_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `crop_types`

```sql
CREATE TABLE `crop_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `crop_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `standard_day_degree` double DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `load_estimation_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`load_estimation_data`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_types_product_id_foreign` (`crop_id`),
  KEY `crop_types_created_by_index` (`created_by`),
  CONSTRAINT `crop_types_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `product_types_product_id_foreign` FOREIGN KEY (`crop_id`) REFERENCES `crops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `drivers`

```sql
CREATE TABLE `drivers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tractor_id` bigint(20) unsigned DEFAULT NULL,
  `farm_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `mobile` varchar(255) NOT NULL,
  `employee_code` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `drivers_tractor_id_foreign` (`tractor_id`),
  KEY `drivers_farm_id_foreign` (`farm_id`),
  CONSTRAINT `drivers_farm_id_foreign` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=78 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `failed_jobs`

```sql
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=37861 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `farms`

```sql
CREATE TABLE `farms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `coordinates` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`coordinates`)),
  `zoom` int(11) NOT NULL DEFAULT 15,
  `center` varchar(255) NOT NULL,
  `area` double NOT NULL,
  `crop_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=93 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `farm_plans`

```sql
CREATE TABLE `farm_plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `farm_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `goal` text DEFAULT NULL,
  `referrer` varchar(255) DEFAULT NULL,
  `counselors` varchar(255) DEFAULT NULL,
  `executors` varchar(255) DEFAULT NULL,
  `statistical_counselors` varchar(255) DEFAULT NULL,
  `implementation_location` varchar(255) DEFAULT NULL,
  `used_materials` text DEFAULT NULL,
  `evaluation_criteria` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `start_date` varchar(255) NOT NULL,
  `end_date` varchar(255) DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `unique_id` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `farm_plans_unique_id_unique` (`unique_id`),
  KEY `plans_farm_id_foreign` (`farm_id`),
  KEY `plans_created_by_foreign` (`created_by`),
  CONSTRAINT `plans_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `plans_farm_id_foreign` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `farm_plan_details`

```sql
CREATE TABLE `farm_plan_details` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `farm_plan_id` bigint(20) unsigned NOT NULL,
  `treatment_id` bigint(20) unsigned NOT NULL,
  `treatable_type` varchar(255) NOT NULL,
  `treatable_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `features_plan_id_foreign` (`farm_plan_id`),
  KEY `features_timar_id_foreign` (`treatment_id`),
  KEY `features_timarable_type_timarable_id_index` (`treatable_type`,`treatable_id`),
  CONSTRAINT `features_plan_id_foreign` FOREIGN KEY (`farm_plan_id`) REFERENCES `farm_plans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `features_timar_id_foreign` FOREIGN KEY (`treatment_id`) REFERENCES `treatments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=79 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `farm_reports`

```sql
CREATE TABLE `farm_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `farm_id` bigint(20) unsigned NOT NULL,
  `date` date NOT NULL,
  `operation_id` bigint(20) unsigned NOT NULL,
  `reportable_type` varchar(255) NOT NULL,
  `reportable_id` bigint(20) unsigned NOT NULL,
  `labour_id` int(11) NOT NULL,
  `description` text NOT NULL,
  `value` double DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `farm_reports_farm_id_foreign` (`farm_id`),
  KEY `farm_reports_operation_id_foreign` (`operation_id`),
  KEY `farm_reports_reportable_type_reportable_id_index` (`reportable_type`,`reportable_id`),
  KEY `farm_reports_created_by_foreign` (`created_by`),
  CONSTRAINT `farm_reports_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `farm_reports_farm_id_foreign` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `farm_reports_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1003 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `farm_user`

```sql
CREATE TABLE `farm_user` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `farm_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `is_owner` tinyint(1) NOT NULL DEFAULT 0,
  `role` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `farm_user_farm_id_foreign` (`farm_id`),
  KEY `farm_user_user_id_foreign` (`user_id`),
  CONSTRAINT `farm_user_farm_id_foreign` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `farm_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=324 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `features`

```sql
CREATE TABLE `features` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `plan_id` bigint(20) unsigned NOT NULL,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`name`)),
  `slug` varchar(255) NOT NULL,
  `description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`description`)),
  `value` varchar(255) NOT NULL,
  `resettable_period` smallint(5) unsigned NOT NULL DEFAULT 0,
  `resettable_interval` varchar(255) NOT NULL DEFAULT 'month',
  `sort_order` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `features_plan_id_slug_unique` (`plan_id`,`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `fields`

```sql
CREATE TABLE `fields` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `farm_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `coordinates` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`coordinates`)),
  `center` varchar(255) NOT NULL,
  `area` double NOT NULL,
  `crop_type_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `unique_id` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fields_unique_id_unique` (`unique_id`),
  KEY `fields_farm_id_foreign` (`farm_id`),
  CONSTRAINT `fields_farm_id_foreign` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=247 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `frostbit_risks`

```sql
CREATE TABLE `frostbit_risks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `farm_id` bigint(20) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  `notify` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `frostbit_risks_farm_id_foreign` (`farm_id`),
  CONSTRAINT `frostbit_risks_farm_id_foreign` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `gps_data`

```sql
CREATE TABLE `gps_data` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tractor_id` bigint(20) unsigned NOT NULL,
  `coordinate` varchar(255) NOT NULL,
  `speed` int(10) unsigned NOT NULL,
  `status` tinyint(3) unsigned NOT NULL,
  `directions` varchar(255) NOT NULL,
  `imei` varchar(20) NOT NULL,
  `date_time` datetime NOT NULL,
  PRIMARY KEY (`id`,`date_time`),
  KEY `gps_data_imei_index` (`imei`),
  KEY `gps_data_tractor_id_index` (`tractor_id`),
  KEY `gps_data_tractor_id_date_time_index` (`tractor_id`,`date_time`),
  KEY `idx_gps_data_start_time_detection` (`tractor_id`,`date_time`,`status`,`speed`)
) ENGINE=InnoDB AUTO_INCREMENT=18220771 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
 PARTITION BY RANGE (year(`date_time`) * 10000 + month(`date_time`) * 100 + dayofmonth(`date_time`))
(PARTITION `p20251022` VALUES LESS THAN (20251023) ENGINE = InnoDB,
 PARTITION `p20251023` VALUES LESS THAN (20251024) ENGINE = InnoDB,
 PARTITION `p20251024` VALUES LESS THAN (20251025) ENGINE = InnoDB,
 PARTITION `p20251025` VALUES LESS THAN (20251026) ENGINE = InnoDB,
 PARTITION `p20251026` VALUES LESS THAN (20251027) ENGINE = InnoDB,
 PARTITION `p20251027` VALUES LESS THAN (20251028) ENGINE = InnoDB,
 PARTITION `p20251028` VALUES LESS THAN (20251029) ENGINE = InnoDB,
 PARTITION `p_future` VALUES LESS THAN MAXVALUE ENGINE = InnoDB);
```


## Table: `gps_devices`

```sql
CREATE TABLE `gps_devices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `device_type` enum('mobile_phone','personal_gps','tractor_gps') DEFAULT NULL,
  `device_fingerprint` varchar(255) DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `tractor_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `imei` varchar(255) DEFAULT NULL,
  `sim_number` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gps_devices_imei_unique` (`imei`),
  UNIQUE KEY `gps_devices_sim_number_unique` (`sim_number`),
  UNIQUE KEY `gps_devices_device_fingerprint_unique` (`device_fingerprint`),
  KEY `gps_devices_user_id_foreign` (`user_id`),
  CONSTRAINT `gps_devices_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=67 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `gps_metrics_calculations`

```sql
CREATE TABLE `gps_metrics_calculations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tractor_id` bigint(20) unsigned NOT NULL,
  `traveled_distance` double NOT NULL,
  `work_duration` bigint(20) unsigned NOT NULL,
  `stoppage_count` int(11) NOT NULL,
  `stoppage_duration` bigint(20) unsigned NOT NULL,
  `stoppage_duration_while_on` bigint(20) unsigned NOT NULL DEFAULT 0,
  `stoppage_duration_while_off` bigint(20) unsigned NOT NULL DEFAULT 0,
  `average_speed` double NOT NULL,
  `efficiency` double NOT NULL,
  `timings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`timings`)),
  `date` date NOT NULL,
  `tractor_task_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `gps_daily_reports_tractor_id_foreign` (`tractor_id`),
  KEY `gps_daily_reports_tractor_task_id_foreign` (`tractor_task_id`),
  CONSTRAINT `gps_daily_reports_tractor_task_id_foreign` FOREIGN KEY (`tractor_task_id`) REFERENCES `tractor_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gps_daily_reports_trucktor_id_foreign` FOREIGN KEY (`tractor_id`) REFERENCES `tractors` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3457 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `irrigations`

```sql
CREATE TABLE `irrigations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `farm_id` bigint(20) unsigned NOT NULL,
  `labour_id` bigint(20) unsigned NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime DEFAULT NULL,
  `note` text DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `is_verified_by_admin` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `pump_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `irrigations_labour_id_foreign` (`labour_id`),
  KEY `irrigations_created_by_foreign` (`created_by`),
  KEY `irrigations_farm_id_foreign` (`farm_id`),
  KEY `irrigations_pump_id_foreign` (`pump_id`),
  CONSTRAINT `irrigations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `irrigations_farm_id_foreign` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `irrigations_labour_id_foreign` FOREIGN KEY (`labour_id`) REFERENCES `labours` (`id`),
  CONSTRAINT `irrigations_pump_id_foreign` FOREIGN KEY (`pump_id`) REFERENCES `pumps` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1124 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `irrigation_plot`

```sql
CREATE TABLE `irrigation_plot` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `plot_id` bigint(20) unsigned NOT NULL,
  `irrigation_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `field_irrigation_field_id_foreign` (`plot_id`),
  KEY `field_irrigation_irrigation_id_foreign` (`irrigation_id`),
  CONSTRAINT `field_irrigation_irrigation_id_foreign` FOREIGN KEY (`irrigation_id`) REFERENCES `irrigations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3600 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `irrigation_plot_new`

```sql
CREATE TABLE `irrigation_plot_new` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `plot_id` bigint(20) unsigned NOT NULL,
  `irrigation_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `irrigation_plot_new_plot_id_foreign` (`plot_id`),
  KEY `irrigation_plot_new_irrigation_id_foreign` (`irrigation_id`),
  CONSTRAINT `irrigation_plot_new_irrigation_id_foreign` FOREIGN KEY (`irrigation_id`) REFERENCES `irrigations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `irrigation_plot_new_plot_id_foreign` FOREIGN KEY (`plot_id`) REFERENCES `plots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `irrigation_valve`

```sql
CREATE TABLE `irrigation_valve` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `irrigation_id` bigint(20) unsigned NOT NULL,
  `valve_id` bigint(20) unsigned NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'closed',
  `opened_at` varchar(255) DEFAULT NULL,
  `closed_at` varchar(255) DEFAULT NULL,
  `duration` bigint(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `irrigation_valve_irrigation_id_foreign` (`irrigation_id`),
  KEY `irrigation_valve_valve_id_foreign` (`valve_id`),
  CONSTRAINT `irrigation_valve_irrigation_id_foreign` FOREIGN KEY (`irrigation_id`) REFERENCES `irrigations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `irrigation_valve_valve_id_foreign` FOREIGN KEY (`valve_id`) REFERENCES `valves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3581 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `jobs`

```sql
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB AUTO_INCREMENT=557 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `job_batches`

```sql
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `labours`

```sql
CREATE TABLE `labours` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `farm_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `personnel_number` varchar(255) DEFAULT NULL,
  `mobile` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `labours_farm_id_index` (`farm_id`),
  KEY `labours_user_id_foreign` (`user_id`),
  CONSTRAINT `labours_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=118 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `labour_team`

```sql
CREATE TABLE `labour_team` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `labour_id` bigint(20) unsigned NOT NULL,
  `team_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `labour_team_labour_id_foreign` (`labour_id`),
  KEY `labour_team_team_id_foreign` (`team_id`),
  CONSTRAINT `labour_team_labour_id_foreign` FOREIGN KEY (`labour_id`) REFERENCES `labours` (`id`) ON DELETE CASCADE,
  CONSTRAINT `labour_team_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `load_estimation_tables`

```sql
CREATE TABLE `load_estimation_tables` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `crop_type_id` bigint(20) unsigned NOT NULL,
  `headers` text NOT NULL,
  `rows` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`rows`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `load_prediction_tables_crop_type_id_foreign` (`crop_type_id`),
  CONSTRAINT `load_prediction_tables_crop_type_id_foreign` FOREIGN KEY (`crop_type_id`) REFERENCES `crop_types` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `maintenances`

```sql
CREATE TABLE `maintenances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `farm_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `maintenances_farm_id_foreign` (`farm_id`),
  CONSTRAINT `maintenances_farm_id_foreign` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `maintenance_reports`

```sql
CREATE TABLE `maintenance_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maintenance_id` bigint(20) unsigned NOT NULL,
  `maintainable_type` varchar(255) NOT NULL,
  `maintainable_id` bigint(20) unsigned NOT NULL,
  `date` date NOT NULL,
  `description` text NOT NULL,
  `repair_shop_entered_at` timestamp NULL DEFAULT NULL,
  `repair_shop_exited_at` timestamp NULL DEFAULT NULL,
  `next_maintenance_km` decimal(10,2) DEFAULT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `maintained_by` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `maintenance_reports_maintenance_id_foreign` (`maintenance_id`),
  KEY `maintenance_reports_maintainable_type_maintainable_id_index` (`maintainable_type`,`maintainable_id`),
  KEY `maintenance_reports_created_by_foreign` (`created_by`),
  KEY `maintenance_reports_maintained_by_foreign` (`maintained_by`),
  CONSTRAINT `maintenance_reports_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `maintenance_reports_maintained_by_foreign` FOREIGN KEY (`maintained_by`) REFERENCES `labours` (`id`) ON DELETE CASCADE,
  CONSTRAINT `maintenance_reports_maintenance_id_foreign` FOREIGN KEY (`maintenance_id`) REFERENCES `maintenances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `media`

```sql
CREATE TABLE `media` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  `uuid` char(36) DEFAULT NULL,
  `collection_name` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `mime_type` varchar(255) DEFAULT NULL,
  `disk` varchar(255) NOT NULL,
  `conversions_disk` varchar(255) DEFAULT NULL,
  `size` bigint(20) unsigned NOT NULL,
  `manipulations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`manipulations`)),
  `custom_properties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`custom_properties`)),
  `generated_conversions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`generated_conversions`)),
  `responsive_images` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`responsive_images`)),
  `order_column` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `media_uuid_unique` (`uuid`),
  KEY `media_model_type_model_id_index` (`model_type`,`model_id`),
  KEY `media_order_column_index` (`order_column`)
) ENGINE=InnoDB AUTO_INCREMENT=77 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `migrations`

```sql
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=188 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `notifications`

```sql
CREATE TABLE `notifications` (
  `id` char(36) NOT NULL,
  `type` varchar(255) NOT NULL,
  `notifiable_type` varchar(255) NOT NULL,
  `notifiable_id` bigint(20) unsigned NOT NULL,
  `data` text NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `nutrient_diagnosis_requests`

```sql
CREATE TABLE `nutrient_diagnosis_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `farm_id` bigint(20) unsigned NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `approved_at` datetime DEFAULT NULL,
  `response_description` text DEFAULT NULL,
  `response_attachment` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `nutrient_diagnosis_requests_user_id_foreign` (`user_id`),
  KEY `nutrient_diagnosis_requests_farm_id_foreign` (`farm_id`),
  CONSTRAINT `nutrient_diagnosis_requests_farm_id_foreign` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `nutrient_diagnosis_requests_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `nutrient_samples`

```sql
CREATE TABLE `nutrient_samples` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nutrient_diagnosis_request_id` bigint(20) unsigned NOT NULL,
  `field_id` bigint(20) unsigned NOT NULL,
  `field_area` decimal(10,2) NOT NULL,
  `load_amount` decimal(10,2) NOT NULL,
  `nitrogen` decimal(10,2) NOT NULL,
  `phosphorus` decimal(10,2) NOT NULL,
  `potassium` decimal(10,2) NOT NULL,
  `calcium` decimal(10,2) NOT NULL,
  `magnesium` decimal(10,2) NOT NULL,
  `iron` decimal(10,2) NOT NULL,
  `copper` decimal(10,2) NOT NULL,
  `zinc` decimal(10,2) NOT NULL,
  `boron` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `nutrient_samples_nutrient_diagnosis_request_id_foreign` (`nutrient_diagnosis_request_id`),
  KEY `nutrient_samples_field_id_foreign` (`field_id`),
  CONSTRAINT `nutrient_samples_field_id_foreign` FOREIGN KEY (`field_id`) REFERENCES `fields` (`id`) ON DELETE CASCADE,
  CONSTRAINT `nutrient_samples_nutrient_diagnosis_request_id_foreign` FOREIGN KEY (`nutrient_diagnosis_request_id`) REFERENCES `nutrient_diagnosis_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `operations`

```sql
CREATE TABLE `operations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `farm_id` bigint(20) unsigned NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `operations_farm_id_foreign` (`farm_id`),
  KEY `operations_parent_id_foreign` (`parent_id`),
  CONSTRAINT `operations_farm_id_foreign` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `operations_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `operations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=73 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `password_reset_tokens`

```sql
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `payments`

```sql
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `amount` bigint(20) unsigned NOT NULL,
  `description` varchar(255) NOT NULL,
  `authority` varchar(255) DEFAULT NULL,
  `reference_id` varchar(255) DEFAULT NULL,
  `card_pan` varchar(255) DEFAULT NULL,
  `card_hash` varchar(255) DEFAULT NULL,
  `status` enum('pending','completed','failed','canceled') NOT NULL DEFAULT 'pending',
  `payable_type` varchar(255) NOT NULL,
  `payable_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payments_user_id_foreign` (`user_id`),
  KEY `payments_payable_type_payable_id_index` (`payable_type`,`payable_id`),
  CONSTRAINT `payments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `permissions`

```sql
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB AUTO_INCREMENT=82 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `personal_access_tokens`

```sql
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB AUTO_INCREMENT=13795 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `pests`

```sql
CREATE TABLE `pests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `scientific_name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `damage` text DEFAULT NULL,
  `management` text DEFAULT NULL,
  `standard_day_degree` double DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pests_name_unique` (`name`),
  KEY `pests_created_by_index` (`created_by`),
  CONSTRAINT `pests_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `phonology_guide_files`

```sql
CREATE TABLE `phonology_guide_files` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `phonologyable_type` varchar(255) NOT NULL,
  `phonologyable_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `phonology_guide_files_phonologyable_type_phonologyable_id_index` (`phonologyable_type`,`phonologyable_id`),
  KEY `phonology_guide_files_created_by_foreign` (`created_by`),
  CONSTRAINT `phonology_guide_files_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `plans`

```sql
CREATE TABLE `plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`name`)),
  `slug` varchar(255) NOT NULL,
  `description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`description`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `price` decimal(8,2) NOT NULL DEFAULT 0.00,
  `signup_fee` decimal(8,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(3) NOT NULL,
  `trial_period` smallint(5) unsigned NOT NULL DEFAULT 0,
  `trial_interval` varchar(255) NOT NULL DEFAULT 'day',
  `invoice_period` smallint(5) unsigned NOT NULL DEFAULT 0,
  `invoice_interval` varchar(255) NOT NULL DEFAULT 'month',
  `grace_period` smallint(5) unsigned NOT NULL DEFAULT 0,
  `grace_interval` varchar(255) NOT NULL DEFAULT 'day',
  `prorate_day` tinyint(3) unsigned DEFAULT NULL,
  `prorate_period` tinyint(3) unsigned DEFAULT NULL,
  `prorate_extend_due` tinyint(3) unsigned DEFAULT NULL,
  `active_subscribers_limit` smallint(5) unsigned DEFAULT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plans_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `plots`

```sql
CREATE TABLE `plots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `field_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `coordinates` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`coordinates`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `unique_id` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plots_unique_id_unique` (`unique_id`),
  KEY `blocks_field_id_foreign` (`field_id`),
  CONSTRAINT `blocks_field_id_foreign` FOREIGN KEY (`field_id`) REFERENCES `fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=379 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `profiles`

```sql
CREATE TABLE `profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `province` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `company` varchar(255) DEFAULT NULL,
  `personnel_number` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `profiles_user_id_foreign` (`user_id`),
  CONSTRAINT `profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=226 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `pumps`

```sql
CREATE TABLE `pumps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `farm_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `serial_number` varchar(255) NOT NULL,
  `model` varchar(255) NOT NULL,
  `manufacturer` varchar(255) NOT NULL,
  `horsepower` int(10) unsigned NOT NULL,
  `phase` tinyint(4) NOT NULL,
  `voltage` int(10) unsigned NOT NULL,
  `ampere` int(10) unsigned NOT NULL,
  `rpm` int(10) unsigned NOT NULL,
  `tempurature` int(11) DEFAULT NULL,
  `pipe_size` double NOT NULL,
  `debi` double NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `is_healthy` tinyint(1) NOT NULL DEFAULT 1,
  `location` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pumps_farm_id_foreign` (`farm_id`),
  CONSTRAINT `pumps_farm_id_foreign` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `roles`

```sql
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `role_has_permissions`

```sql
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `rows`

```sql
CREATE TABLE `rows` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `field_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `coordinates` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `unique_id` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rows_unique_id_unique` (`unique_id`),
  KEY `rows_field_id_foreign` (`field_id`),
  CONSTRAINT `rows_field_id_foreign` FOREIGN KEY (`field_id`) REFERENCES `fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=374 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `sliders`

```sql
CREATE TABLE `sliders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `page` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `images` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`images`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `interval` int(11) NOT NULL DEFAULT 5,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `subscriptions`

```sql
CREATE TABLE `subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subscriber_type` varchar(255) NOT NULL,
  `subscriber_id` bigint(20) unsigned NOT NULL,
  `plan_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `timezone` varchar(255) DEFAULT NULL,
  `trial_ends_at` datetime DEFAULT NULL,
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `cancels_at` datetime DEFAULT NULL,
  `canceled_at` datetime DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subscriptions_subscriber_type_subscriber_id_index` (`subscriber_type`,`subscriber_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `subscription_usage`

```sql
CREATE TABLE `subscription_usage` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subscription_id` bigint(20) unsigned NOT NULL,
  `feature_id` bigint(20) unsigned NOT NULL,
  `used` smallint(5) unsigned NOT NULL,
  `timezone` varchar(255) DEFAULT NULL,
  `valid_until` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `teams`

```sql
CREATE TABLE `teams` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `farm_id` bigint(20) unsigned NOT NULL,
  `supervisor_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `teams_farm_id_foreign` (`farm_id`),
  CONSTRAINT `teams_farm_id_foreign` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `telescope_entries`

```sql
CREATE TABLE `telescope_entries` (
  `sequence` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `batch_id` char(36) NOT NULL,
  `family_hash` varchar(255) DEFAULT NULL,
  `should_display_on_index` tinyint(1) NOT NULL DEFAULT 1,
  `type` varchar(20) NOT NULL,
  `content` longtext NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`sequence`),
  UNIQUE KEY `telescope_entries_uuid_unique` (`uuid`),
  KEY `telescope_entries_batch_id_index` (`batch_id`),
  KEY `telescope_entries_family_hash_index` (`family_hash`),
  KEY `telescope_entries_created_at_index` (`created_at`),
  KEY `telescope_entries_type_should_display_on_index_index` (`type`,`should_display_on_index`)
) ENGINE=InnoDB AUTO_INCREMENT=76202120 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `telescope_entries_tags`

```sql
CREATE TABLE `telescope_entries_tags` (
  `entry_uuid` char(36) NOT NULL,
  `tag` varchar(255) NOT NULL,
  PRIMARY KEY (`entry_uuid`,`tag`),
  KEY `telescope_entries_tags_tag_index` (`tag`),
  CONSTRAINT `telescope_entries_tags_entry_uuid_foreign` FOREIGN KEY (`entry_uuid`) REFERENCES `telescope_entries` (`uuid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `telescope_monitoring`

```sql
CREATE TABLE `telescope_monitoring` (
  `tag` varchar(255) NOT NULL,
  PRIMARY KEY (`tag`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `tractors`

```sql
CREATE TABLE `tractors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `farm_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `start_work_time` varchar(255) NOT NULL,
  `end_work_time` varchar(255) NOT NULL,
  `expected_daily_work_time` varchar(255) NOT NULL,
  `expected_monthly_work_time` varchar(255) NOT NULL,
  `expected_yearly_work_time` varchar(255) NOT NULL,
  `is_working` tinyint(1) NOT NULL DEFAULT 0,
  `is_in_repair_shop` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `last_activity` timestamp NULL DEFAULT NULL,
  `last_service_at` datetime DEFAULT NULL,
  `last_service_notified_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `trucktors_farm_id_foreign` (`farm_id`),
  CONSTRAINT `trucktors_farm_id_foreign` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=86 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `tractor_reports`

```sql
CREATE TABLE `tractor_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tractor_id` bigint(20) unsigned NOT NULL,
  `date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `operation_id` bigint(20) unsigned NOT NULL,
  `field_id` bigint(20) unsigned NOT NULL,
  `description` text DEFAULT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `trucktor_reports_trucktor_id_foreign` (`tractor_id`),
  KEY `trucktor_reports_operation_id_foreign` (`operation_id`),
  KEY `trucktor_reports_field_id_foreign` (`field_id`),
  KEY `trucktor_reports_created_by_foreign` (`created_by`),
  CONSTRAINT `trucktor_reports_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `trucktor_reports_field_id_foreign` FOREIGN KEY (`field_id`) REFERENCES `fields` (`id`) ON DELETE CASCADE,
  CONSTRAINT `trucktor_reports_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `trucktor_reports_trucktor_id_foreign` FOREIGN KEY (`tractor_id`) REFERENCES `tractors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=266 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `tractor_tasks`

```sql
CREATE TABLE `tractor_tasks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tractor_id` bigint(20) unsigned NOT NULL,
  `operation_id` bigint(20) unsigned NOT NULL,
  `status` enum('not_started','not_done','in_progress','done','stopped') NOT NULL DEFAULT 'not_started',
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `trucktor_tasks_trucktor_id_foreign` (`tractor_id`),
  KEY `trucktor_tasks_operation_id_foreign` (`operation_id`),
  KEY `trucktor_tasks_created_by_foreign` (`created_by`),
  CONSTRAINT `trucktor_tasks_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `trucktor_tasks_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `operations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `trucktor_tasks_trucktor_id_foreign` FOREIGN KEY (`tractor_id`) REFERENCES `tractors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=615 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `tractor_task_taskables`

```sql
CREATE TABLE `tractor_task_taskables` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tractor_task_id` bigint(20) unsigned NOT NULL,
  `taskable_type` varchar(255) NOT NULL,
  `taskable_id` bigint(20) unsigned NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tractor_task_taskables_task_unique` (`tractor_task_id`,`taskable_type`,`taskable_id`),
  KEY `tractor_task_taskables_taskable_type_taskable_id_index` (`taskable_type`,`taskable_id`),
  CONSTRAINT `tractor_task_taskables_tractor_task_id_foreign` FOREIGN KEY (`tractor_task_id`) REFERENCES `tractor_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=683 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `treatments`

```sql
CREATE TABLE `treatments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `farm_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `color` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `timars_farm_id_foreign` (`farm_id`),
  CONSTRAINT `timars_farm_id_foreign` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `trees`

```sql
CREATE TABLE `trees` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `row_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `location` varchar(255) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `unique_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `trees_unique_id_unique` (`unique_id`),
  KEY `trees_row_id_foreign` (`row_id`),
  CONSTRAINT `trees_row_id_foreign` FOREIGN KEY (`row_id`) REFERENCES `rows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2819 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `users`

```sql
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(255) DEFAULT NULL,
  `mobile` varchar(255) NOT NULL,
  `mobile_verified_at` timestamp NULL DEFAULT NULL,
  `last_activity_at` timestamp NULL DEFAULT NULL,
  `fcm_token` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `password_expires_at` datetime DEFAULT NULL,
  `preferences` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`preferences`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_mobile_unique` (`mobile`),
  UNIQUE KEY `users_username_unique` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=210 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `user_has_permissions`

```sql
CREATE TABLE `user_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `user_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `user_has_roles`

```sql
CREATE TABLE `user_has_roles` (
  `role_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `user_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `valves`

```sql
CREATE TABLE `valves` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `unique_id` varchar(255) DEFAULT NULL,
  `plot_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `location` varchar(255) NOT NULL,
  `is_open` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `irrigation_area` double NOT NULL,
  `dripper_count` int(11) NOT NULL,
  `dripper_flow_rate` double NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `valves_unique_id_unique` (`unique_id`)
) ENGINE=InnoDB AUTO_INCREMENT=386 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `verify_mobile_tokens`

```sql
CREATE TABLE `verify_mobile_tokens` (
  `mobile` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`mobile`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `volk_oil_sprays`

```sql
CREATE TABLE `volk_oil_sprays` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `farm_id` bigint(20) unsigned NOT NULL,
  `start_dt` date NOT NULL,
  `end_dt` date NOT NULL,
  `min_temp` int(10) unsigned NOT NULL,
  `max_temp` int(10) unsigned NOT NULL,
  `cold_requirement` int(10) unsigned NOT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cold_requirement_notifications_farm_id_foreign` (`farm_id`),
  KEY `cold_requirement_notifications_created_by_foreign` (`created_by`),
  CONSTRAINT `cold_requirement_notifications_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cold_requirement_notifications_farm_id_foreign` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `warnings`

```sql
CREATE TABLE `warnings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `farm_id` bigint(20) unsigned NOT NULL,
  `key` varchar(255) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `parameters` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `type` enum('one-time','schedule-based','condition-based') NOT NULL DEFAULT 'one-time',
  PRIMARY KEY (`id`),
  KEY `warnings_farm_id_foreign` (`farm_id`),
  CONSTRAINT `warnings_farm_id_foreign` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Table: `work_shifts`

```sql
CREATE TABLE `work_shifts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `farm_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `work_hours` decimal(5,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `work_shifts_farm_id_foreign` (`farm_id`),
  CONSTRAINT `work_shifts_farm_id_foreign` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```


## Foreign Keys

| Table | Column | References | Constraint |
|---|---|---|---|
| app_releases | created_by | users.id | app_releases_created_by_foreign |
| attachments | user_id | users.id | attachments_user_id_foreign |
| attendance_daily_reports | user_id | users.id | labour_daily_reports_user_id_foreign |
| attendance_daily_reports | approved_by | users.id | worker_daily_reports_approved_by_foreign |
| attendance_gps_data | user_id | users.id | labour_gps_data_user_id_foreign |
| attendance_monthly_payrolls | user_id | users.id | labour_monthly_payrolls_user_id_foreign |
| attendance_sessions | user_id | users.id | labour_attendance_sessions_user_id_foreign |
| attendance_shift_schedules | user_id | users.id | labour_shift_schedules_user_id_foreign |
| attendance_shift_schedules | shift_id | work_shifts.id | worker_shift_schedules_shift_id_foreign |
| attendance_trackings | farm_id | farms.id | attendance_trackings_farm_id_foreign |
| attendance_trackings | user_id | users.id | attendance_trackings_user_id_foreign |
| crops | created_by | users.id | crops_created_by_foreign |
| crop_types | created_by | users.id | crop_types_created_by_foreign |
| crop_types | crop_id | crops.id | product_types_product_id_foreign |
| drivers | farm_id | farms.id | drivers_farm_id_foreign |
| farm_plans | created_by | users.id | plans_created_by_foreign |
| farm_plans | farm_id | farms.id | plans_farm_id_foreign |
| farm_plan_details | farm_plan_id | farm_plans.id | features_plan_id_foreign |
| farm_plan_details | treatment_id | treatments.id | features_timar_id_foreign |
| farm_reports | created_by | users.id | farm_reports_created_by_foreign |
| farm_reports | farm_id | farms.id | farm_reports_farm_id_foreign |
| farm_reports | operation_id | operations.id | farm_reports_operation_id_foreign |
| farm_user | farm_id | farms.id | farm_user_farm_id_foreign |
| farm_user | user_id | users.id | farm_user_user_id_foreign |
| fields | farm_id | farms.id | fields_farm_id_foreign |
| frostbit_risks | farm_id | farms.id | frostbit_risks_farm_id_foreign |
| gps_devices | user_id | users.id | gps_devices_user_id_foreign |
| gps_metrics_calculations | tractor_task_id | tractor_tasks.id | gps_daily_reports_tractor_task_id_foreign |
| gps_metrics_calculations | tractor_id | tractors.id | gps_daily_reports_trucktor_id_foreign |
| irrigations | created_by | users.id | irrigations_created_by_foreign |
| irrigations | farm_id | farms.id | irrigations_farm_id_foreign |
| irrigations | labour_id | labours.id | irrigations_labour_id_foreign |
| irrigations | pump_id | pumps.id | irrigations_pump_id_foreign |
| irrigation_plot | irrigation_id | irrigations.id | field_irrigation_irrigation_id_foreign |
| irrigation_plot_new | irrigation_id | irrigations.id | irrigation_plot_new_irrigation_id_foreign |
| irrigation_plot_new | plot_id | plots.id | irrigation_plot_new_plot_id_foreign |
| irrigation_valve | irrigation_id | irrigations.id | irrigation_valve_irrigation_id_foreign |
| irrigation_valve | valve_id | valves.id | irrigation_valve_valve_id_foreign |
| labours | user_id | users.id | labours_user_id_foreign |
| labour_team | labour_id | labours.id | labour_team_labour_id_foreign |
| labour_team | team_id | teams.id | labour_team_team_id_foreign |
| load_estimation_tables | crop_type_id | crop_types.id | load_prediction_tables_crop_type_id_foreign |
| maintenances | farm_id | farms.id | maintenances_farm_id_foreign |
| maintenance_reports | created_by | users.id | maintenance_reports_created_by_foreign |
| maintenance_reports | maintained_by | labours.id | maintenance_reports_maintained_by_foreign |
| maintenance_reports | maintenance_id | maintenances.id | maintenance_reports_maintenance_id_foreign |
| nutrient_diagnosis_requests | farm_id | farms.id | nutrient_diagnosis_requests_farm_id_foreign |
| nutrient_diagnosis_requests | user_id | users.id | nutrient_diagnosis_requests_user_id_foreign |
| nutrient_samples | field_id | fields.id | nutrient_samples_field_id_foreign |
| nutrient_samples | nutrient_diagnosis_request_id | nutrient_diagnosis_requests.id | nutrient_samples_nutrient_diagnosis_request_id_foreign |
| operations | farm_id | farms.id | operations_farm_id_foreign |
| operations | parent_id | operations.id | operations_parent_id_foreign |
| payments | user_id | users.id | payments_user_id_foreign |
| pests | created_by | users.id | pests_created_by_foreign |
| phonology_guide_files | created_by | users.id | phonology_guide_files_created_by_foreign |
| plots | field_id | fields.id | blocks_field_id_foreign |
| profiles | user_id | users.id | profiles_user_id_foreign |
| pumps | farm_id | farms.id | pumps_farm_id_foreign |
| role_has_permissions | permission_id | permissions.id | role_has_permissions_permission_id_foreign |
| role_has_permissions | role_id | roles.id | role_has_permissions_role_id_foreign |
| rows | field_id | fields.id | rows_field_id_foreign |
| teams | farm_id | farms.id | teams_farm_id_foreign |
| telescope_entries_tags | entry_uuid | telescope_entries.uuid | telescope_entries_tags_entry_uuid_foreign |
| tractors | farm_id | farms.id | trucktors_farm_id_foreign |
| tractor_reports | created_by | users.id | trucktor_reports_created_by_foreign |
| tractor_reports | field_id | fields.id | trucktor_reports_field_id_foreign |
| tractor_reports | operation_id | operations.id | trucktor_reports_operation_id_foreign |
| tractor_reports | tractor_id | tractors.id | trucktor_reports_trucktor_id_foreign |
| tractor_tasks | created_by | users.id | trucktor_tasks_created_by_foreign |
| tractor_tasks | operation_id | operations.id | trucktor_tasks_operation_id_foreign |
| tractor_tasks | tractor_id | tractors.id | trucktor_tasks_trucktor_id_foreign |
| tractor_task_taskables | tractor_task_id | tractor_tasks.id | tractor_task_taskables_tractor_task_id_foreign |
| treatments | farm_id | farms.id | timars_farm_id_foreign |
| trees | row_id | rows.id | trees_row_id_foreign |
| user_has_permissions | permission_id | permissions.id | user_has_permissions_permission_id_foreign |
| user_has_roles | role_id | roles.id | user_has_roles_role_id_foreign |
| volk_oil_sprays | created_by | users.id | cold_requirement_notifications_created_by_foreign |
| volk_oil_sprays | farm_id | farms.id | cold_requirement_notifications_farm_id_foreign |
| warnings | farm_id | farms.id | warnings_farm_id_foreign |
| work_shifts | farm_id | farms.id | work_shifts_farm_id_foreign |
