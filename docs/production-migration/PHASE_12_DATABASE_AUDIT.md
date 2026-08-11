# Phase 12 — Database Audit (Read-Only)

**Database:** `api_db`  
**Engine:** MariaDB **10.6.25**  
**Audit date:** 2026-08-05  
**Mode:** READ-ONLY (`SHOW` / `SELECT` / `information_schema` / artisan `db:*` only)  
**Audited against:** `docs/Database Schema Reference.md`, `docs/Migration Risks & Recommendations.md`

---

## 1. Engine & Server Configuration

| Attribute | Value | Assessment |
|---|---|---|
| Version | 10.6.25-MariaDB | Matches Architecture Report |
| App connection | `127.0.0.1:3306` / user `api_db` | OK |
| Network listen | **`0.0.0.0:3306`** | ❌ Security blocker |
| Tables | 74 | Matches prior snapshot |
| Total size | **~3846 MB (~3.76–3.85 GB)** | Dominated by `gps_data` |
| `innodb_buffer_pool_size` | **128 MB** | ❌ Severely undersized for 3.6 GB GPS table |
| `max_connections` | 151 | Adequate for current workers |
| `character_set_server` | **latin1** | ⚠ Table-level utf8mb4 overrides; staging should use utf8mb4 server default |
| `collation_server` | latin1_swedish_ci | Same note |
| `event_scheduler` | **OFF** | No automatic partition maintenance |
| GPS partition events | **0** | Confirmed |

---

## 2. Database & Table Sizes (Top)

| Table | Approx rows (`information_schema`) | Data | Index | Total |
|---|---:|---:|---:|---:|
| **gps_data** | 14,734,378 | 1506.58 MB | 2187.98 MB | **3694.56 MB** |
| telescope_entries | 110,154 | 47.02 MB | 25.16 MB | 72.17 MB |
| notifications | 39,514 | 54.77 MB | 5.55 MB | 60.31 MB |
| trees | 2,200 | 7.52 MB | 0.19 MB | 7.70 MB |
| telescope_entries_tags | 8,133 | 2.02 MB | 3.02 MB | 5.03 MB |
| irrigation_valve | 3,228 | 0.42 MB | 0.20 MB | 0.63 MB |
| gps_metrics_calculations | 2,466 | 0.33 MB | 0.17 MB | 0.50 MB |
| attendance_gps_data | 2,595 | 0.25 MB | 0.17 MB | 0.42 MB |
| failed_jobs | 27 | 0.23 MB | 0.02 MB | 0.25 MB |

`gps_data` ≈ **96%** of database size.

---

## 3. Migration Status

| Check | Result |
|---|---|
| `migrations` table rows | 185 |
| Migration PHP files on disk | 161 |
| Pending migrations | **None** (`migrate:status` shows no Pending) |
| Latest GPS-related | `2026_07_14_000001_add_unique_imei_date_time_to_gps_data_table` — **Ran** (batch 79) |

**Important:** That migration is a **documented no-op by default** unless `GPS_FORCE_UNIQUE_IMEI_DATETIME=true`. Live index state confirms unique was **not** created (see §5).

Historical migration `2025_10_22_235955_rename_device_id_to_gps_device_id_in_gps_data_table` is marked Ran, but **live columns use `tractor_id`**, not `gps_device_id` (manual/subsequent schema evolution; matches Schema Reference).

---

## 4. `gps_data` — Column Model

### Live columns (8)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint unsigned AI | Part of composite PK |
| **`tractor_id`** | bigint unsigned NOT NULL | **Present** |
| `coordinate` | varchar(255) | JSON string `[lat,lon]` |
| `speed` | int unsigned | |
| `status` | tinyint unsigned | |
| `directions` | varchar(255) | |
| `imei` | varchar(20) | |
| `date_time` | datetime | Partition key component |

### tractor_id vs gps_device_id

| Check | Result |
|---|---|
| Column `tractor_id` | **YES** |
| Column `gps_device_id` | **NO** |
| Foreign key on `gps_data` | **NONE** |

**Verdict:** Production stores GPS points keyed by **`tractor_id`**. There is **no** `gps_device_id` column on live `gps_data`. Any staging migration or Go code that assumes `gps_device_id` on `gps_data` is a **schema mismatch risk**.

Device identity for ingest is resolved via `gps_devices.imei` → `gps_devices.tractor_id` → insert `tractor_id` (see `IngestGpsData::resolveTractor`).

---

## 5. `gps_data` — Indexes

| Index | Columns | Unique? |
|---|---|---|
| PRIMARY | `(id, date_time)` | Yes (PK) |
| `gps_data_imei_index` | `imei` | No |
| `gps_data_tractor_id_index` | `tractor_id` | No |
| `gps_data_tractor_id_date_time_index` | `(tractor_id, date_time)` | No |
| `idx_gps_data_start_time_detection` | `(tractor_id, date_time, status, speed)` | No |
| `gps_data_imei_date_time_unique` | — | **ABSENT** |

**Implication:** Gateway replay can create duplicate `(imei, date_time)` rows. Ingest uses `insertOrIgnore` only when unique exists; otherwise plain insert. Matches Migration Risks doc.

---

## 6. `gps_data` — Partition Structure

PARTITION BY RANGE on `YEAR(date_time)*10000 + MONTH*100 + DAY`:

| Partition | Approx rows | Data MB | Index MB |
|---|---:|---:|---:|
| p20251022 | 10,248 | 1.52 | 3.97 |
| p20251023 | 12,145 | 1.52 | 3.95 |
| p20251024 | 10,461 | 1.52 | 3.89 |
| p20251025 | 10,496 | 1.52 | 2.89 |
| p20251026 | 11,396 | 1.52 | 3.92 |
| p20251027 | 4,443 | 0.48 | 0.95 |
| p20251028 | 8,394 | 1.52 | 1.52 |
| **p_future** | **14,666,795** | **1497.00** | **2166.89** |

**Critical:** Nearly all live data sits in `p_future` (partitions frozen since ~Oct 2025). Daily REORGANIZE disabled after production lock incidents. Event scheduler OFF; no `create_daily_gps_data_partitions` event.

**Migration risk:** Do **not** run `gps:ensure-partitions --force` on production-scale data without a maintenance window. Staging should seed recent subset or accept `p_future`-centric layout.

---

## 7. Recent Ingest Activity (Bounded Counts)

| Date | Rows |
|---|---:|
| 2026-08-05 (audit day, ~13:52 local) | **0** |
| 2026-08-04 | 5,462 |
| 2026-08-03 | 4,482 |
| 2026-08-02 | 8,441 |
| 2026-08-01 | 9,665 |
| 2026-07-31 | 0 |
| 2026-07-30 | 3,801 |

Daily rate remains ~4k–10k when devices report. **Zero rows so far today** is an operational observation (possible fleet idle / gateway / binding issue) — documented, not fixed in this phase.

`failed_jobs` count: **27** (recent failures are frost/cold-requirement jobs on `default` queue — not GPS ingest).

---

## 8. `gps_devices` Audit

### Summary

| Metric | Count |
|---:|
| Total devices | 38 |
| With IMEI | 37 |
| Null/empty IMEI | 1 |
| With `tractor_id` | **13** |
| Null `tractor_id` | **25** |
| Duplicate IMEIs | **0** (unique index healthy) |
| Orphan `tractor_id` (points to missing tractor) | **0** |

### By `device_type`

| Type | Count |
|---|---:|
| `tractor_gps` | 21 |
| `mobile_phone` | 2 |
| NULL / empty | 15 |

### Foreign keys

| Constraint | Column | References |
|---|---|---|
| `gps_devices_user_id_foreign` | `user_id` | `users.id` |
| *(none)* | `tractor_id` | **No FK** to `tractors` |

### tractor_gps missing tractor relation

**13** devices with `device_type=tractor_gps` and `tractor_id IS NULL` (IMEIs present). Examples (ids): 3, 8, 18, 20, 24, 26, 30, 47–51, 53.

`IngestGpsData` discards batches for unbound IMEI (no tractor). These devices **will not persist GPS** until rebound.

### Bound devices (13) — IMEI → tractor_id

| device_id | tractor_id | Notes |
|---:|---:|---|
| 27 | 46 | test Android |
| 36–38 | 52/51/49 | Postman/test style |
| 44 | 60 | |
| 52 | 81 | |
| 55 | 85 | |
| 56 | 84 | |
| 62–66 | 61/82/83/38/50 | some `device_type` empty |

### Tractors without a gps_devices row

**64** tractors have no `gps_devices.tractor_id` pointing at them (includes historical duplicates / retired / test units). Not all are expected to be active fleet.

---

## 9. Related GPS Tables

| Table | FK | Notes |
|---|---|---|
| `gps_metrics_calculations` | `tractor_id` → `tractors`; optional `tractor_task_id` | Daily metrics |
| `attendance_gps_data` | `user_id` → `users` | Separate labour path |

---

## 10. Schema vs Reference Docs

| Topic | Schema Reference / Risks | Live 2026-08-05 | Match? |
|---|---|---|---|
| `gps_data` uses `tractor_id` | Yes | Yes | ✅ |
| No `gps_device_id` on `gps_data` | Implied | Confirmed | ✅ |
| Partition collapse to `p_future` | Yes | Yes | ✅ |
| Unique `(imei, date_time)` optional/absent | Yes | Absent | ✅ |
| ~3.6–3.8 GB DB / gps dominates | Yes | Yes | ✅ |
| telescope ~88 MB | Prior | ~72 MB now | ✅ (pruned) |

---

## 11. Database Blockers for Migration (Document Only)

1. **Disk + `gps_data` size** — full dump/restore needs ≥ free space + staging ≥100 GB guidance.
2. **`p_future` collapse** — restore/partition ops are high-risk; never online REORGANIZE without window.
3. **No unique `(imei, date_time)`** — staging must match flag semantics; do not force unique without offline dedupe.
4. **Device binding gaps** — 13 unbound `tractor_gps` IMEIs; binding cleanup is a **data readiness** action (separate change window).
5. **`innodb_buffer_pool_size=128MB`** — performance/OOM risk under path queries; tune on staging/prod carefully.
6. **MySQL exposed on `0.0.0.0`** — must not be replicated to staging; fix before broader network migration work.
7. **Do not run** full-table `COUNT(*)` / `gps:ensure-partitions` / forced unique index as part of migrate automation.

---

*Phase 12 database audit — read-only, 2026-08-05*
