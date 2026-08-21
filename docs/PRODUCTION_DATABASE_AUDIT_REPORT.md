# Production Database Audit Report

**Database:** `api_db`  
**Engine:** MariaDB `10.6.25-MariaDB`  
**Server:** `latin1` / `latin1_swedish_ci`  
**Database size:** `3772.67 MB`  
**Base tables:** `74`  
**Views:** `1`  
**Broken views:** `1`  

## Inventory Snapshot

- `gps_data` is the dominant table at `18149805` rows and `3694.56 MB` total size.
- The largest non-GPS tables are `notifications` (`47721` rows) and `trees` (`2680` rows).
- The only view is `v_gps_delta`, and it is broken because it references the missing table `gps_reports`.

### Core counts

| Entity | Count |
|---|---:|
| users | 201 |
| profiles | 213 |
| roles | 10 |
| permissions | 81 |
| user_has_roles | 226 |
| user_has_permissions | 60 |
| farm_user | 223 |
| farms | 70 |
| fields | 226 |
| plots | 326 |
| rows | 245 |
| trees | 2680 |
| operations | 68 |
| farm_reports | 929 |
| farm_plans | 11 |
| irrigations | 1099 |
| tractors | 92 |
| gps_devices | 38 |
| gps_data | 18149805 |
| media | 54 |

## Identity Audit

- Total users: `201`
- Active users: `201`
- Inactive users: `0`
- Duplicate mobile rows: `0`
- Users without any assigned role: `9`
- Profiles: `213` rows for `200` distinct users, with `13` duplicate profile rows

Actual roles assigned in production: `admin`, `custom-role`, `employee`, `labour`, `operator`, `root`, `viewer`. `consultant`, `inspector`, and `super-admin` have zero assigned users.

ROOT users with farm access: `4` of `5` ROOT accounts. This contradicts the stated rule that ROOT must never receive farms.

## Farm Ownership and Access

- Farm memberships: `223`
- Owner memberships: `66`
- Farms without an owner row: `4`
- Duplicate `farm_user` rows: `5` extra rows across `3` duplicate membership combinations

The full membership matrix is available in `docs/audit/farm_access_matrix.csv`; the ownership matrix is in `docs/audit/farm_ownership_matrix.csv`.

## GIS Audit

| Entity | Geometry type | Count | Null | Invalid | JSON length | SRID |
|---|---|---:|---:|---:|---|---|
| farms | polygon | 70 | 0 | 0 | 3..370 | n/a |
| fields | polygon | 226 | 0 | 0 | 3..78 | n/a |
| plots | polygon | 326 | 0 | 0 | 2..70 | n/a |
| rows | polyline | 245 | 0 | 0 | 2..2 | n/a |
| trees | point | 2680 | 0 | 0 | 1..1 | n/a |

All GIS payloads are JSON-encoded text, not native spatial columns. No SRID is stored in production.

## Operations Audit

| Entity | Count | Date range |
|---|---:|---|
| operations | 68 | 2024-11-30 14:56:26 to 2026-07-03 16:02:24 |
| farm_reports | 929 | 2025-04-03 to 2026-04-23 |
| farm_plans | 11 | 2024-11-22 10:43:48 to 2025-06-14 13:38:31 |

| Check | Value |
|---|---:|
| farm_reports_missing_labour_ref | 29 |
| farm_reports_missing_reportable_ref | 25 |
| farm_reports_missing_reportable_fields_only | 25 |
| operations_missing_parent_ref | 0 |
| farm_plans_missing_farm_ref | 0 |
| farm_plans_missing_created_by_ref | 0 |
| farm_reports_missing_farm_ref | 0 |
| farm_reports_missing_operation_ref | 0 |
| farm_reports_missing_created_by_ref | 0 |

`farm_reports` polymorphic orphaning is concentrated in `App\Models\Field` references (`25` missing field links).

## Irrigation Audit

| Entity | Count | Date range |
|---|---:|---|
| irrigations | 1099 | 2025-01-08 07:00:00 to 2026-08-14 03:00:00 |
| irrigation_plot | 3713 | n/a |
| irrigation_plot_new | 0 | n/a |
| irrigation_valve | 3537 | n/a |
| valves | 338 | n/a |
| pumps | 20 | n/a |

| Check | Value |
|---|---:|
| irrigation_plot_null_plot_link | 0 |
| irrigation_plot_broken_plot_link | 45 |
| valves_null_plot_link | 24 |
| valves_broken_plot_link | 13 |
| irrigation_missing_labour_ref | 0 |
| irrigation_missing_pump_ref | 0 |

## Workforce Audit

| Entity | Count | Date range |
|---|---:|---|
| labours | 114 | 2024-11-27 08:58:20 to 2026-02-10 23:11:28 |
| attendance_daily_reports | 1951 | 2026-01-03 to 2026-08-20 |
| attendance_sessions | 3 | 2026-02-16 to 2026-02-28 |
| attendance_shift_schedules | 18 | 2026-01-27 to 2026-02-09 |
| attendance_trackings | 1 | 2026-02-15 14:28:29 to 2026-02-15 14:28:29 |
| teams | 14 | 2024-11-27 08:58:31 to 2026-04-18 00:40:49 |

| Check | Value |
|---|---:|
| labours_missing_user_ref | 0 |
| attendance_trackings_missing_farm_ref | 0 |
| attendance_trackings_missing_user_ref | 0 |

## Fleet and GPS Audit

- Tractors: `92` across `13` farms
- GPS devices: `38` total, with `1` null IMEI and `27` unbound devices
- GPS data: `18149805` total points from `2004-01-01 03:30:24` to `2068-11-30 07:49:21`
- GPS points missing a matching device by IMEI: `1636226` across `20` distinct orphan IMEIs
- GPS points missing a matching tractor: `0`

Per-device GPS detail is available in `docs/audit/gps_device_summary.csv` and `docs/audit/gps_points_summary.csv`.

## Media Audit

- Media rows: `54`
- Existing files under the default media path: `0`
- Missing files under the default media path: `54`

The public disk currently contains no matching media-library files for the `media` table rows.

## Backup Verification

- Backup directory: `/opt/pistat-production-backups/20260812-183344`
- Dump file: `api_db-full.sql.gz`
- SHA256 for dump matches manifest: `True`
- SHA256 for storage archive matches manifest: `True`
- Gzip integrity: `True`
- Footer present: `True`
- `CREATE TABLE` present: `True`
- `INSERT INTO` present: `True`
- Dump footer: `-- Dump completed on 2026-08-13  5:08:09`

## Production Expected Snapshot

- Users: `201`
- Farms: `70`
- Fields: `226`
- Plots: `326`
- Rows: `245`
- Trees: `2680`
- Operations: `68`
- Reports: `929`
- Farm Plans: `11`
- Irrigations: `1099`
- Tractors: `92`
- Gps Devices: `38`
- Gps Data: `18149805`

## Production Database Final Status

1. Database exact state: MariaDB `10.6.25` on `api_db`, with `74` base tables, `1` view, and `1` broken view.
2. Exact entity counts: captured in `docs/audit/production_row_counts.json` and reflected in the summary files.
3. User farm access matrix: captured in `docs/audit/farm_access_matrix.csv`.
4. Farm ownership matrix: captured in `docs/audit/farm_ownership_matrix.csv`.
5. GIS inventory: all GIS payloads are JSON text; null and invalid geometry counts are `0` for farms, fields, plots, rows, and trees.
6. GPS inventory: `38` devices, `27` unbound, `11` bound to tractors, and `20` distinct orphan IMEIs in telemetry.
7. Telemetry inventory: `18,149,805` GPS points, spanning `2004-01-01 03:30:24` through `2068-11-30 07:49:21`.
8. Backup verification: the production backup dump and storage archive both match SHA256 and the dump stream contains table creation, inserts, and footer completion.
9. Data anomalies: root users currently hold farm memberships, `farm_user` contains duplicate rows, `profiles` contains duplicate rows, `4` farms have no owner row, `farm_reports` has missing field reportables and labour links, `gps_data` has `1,636,226` rows without a matching device, `media` files are all missing, and the view `v_gps_delta` is broken because `gps_reports` does not exist.
