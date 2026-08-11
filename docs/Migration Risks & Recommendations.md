# Migration Risks & Recommendations

**Context:** PiStat production → staging rebuild and future platform improvements  
**Audit date:** 2026-08-04  
**Scope:** Risks identified during read-only production audit

---

## Risk Severity Legend

| Level | Meaning |
|---|---|
| 🔴 Critical | Data loss, outage, or security breach likely without remediation |
| 🟠 High | Significant functional or operational impact |
| 🟡 Medium | Manageable with planning |
| 🟢 Low | Minor; address in normal maintenance |

---

## 1. Infrastructure & Path Drift Risks

### 🔴 Dual deployment root paths

| Path | Still referenced by |
|---|---|
| `/home/api/domains/api.pistatapp.ir/public_html` | `deploy.sh`, GPS supervisor confs (active workers) |
| `/home/api/public_html` | Reverb supervisor, gps-ingest systemd, scheduler cron, Go binary |

**Risk:** Deploying code to domain path does not update Reverb, Go ingest, or scheduler until paths are unified.

**Staging mitigation:**
- Pick **one** canonical path and symlink the other
- Update all supervisor/systemd/cron entries before first staging GPS test
- Verify with: `sudo supervisorctl status`, `systemctl cat gps-ingest`, `crontab -l`

### 🟠 Scheduler cron points to legacy path

```
* * * * * cd /home/api/public_html && flock … php artisan schedule:run
```

**Risk:** Scheduled jobs (GPS metrics, frost alerts, attendance) may run **old code** or fail silently if legacy tree is stale.

**Staging mitigation:** Update cron to domain `public_html` path; verify `schedule:list`.

### 🟠 Supervisor config duplication

`/etc/supervisor/conf.d/` contains duplicate `[program:gps-broadcast]` blocks and mixed symlink targets.

**Risk:** `supervisorctl reread` behavior unpredictable; wrong worker counts after update.

**Staging mitigation:** Clean install from `deploy/supervisor/*.conf` only; remove legacy group wrappers.

---

## 2. Database Risks

### 🔴 gps_data partition collapse into p_future

**Observed state:**

| Partition | Rows | Data |
|---|---:|---:|
| 7 daily partitions (Oct 2025) | ~71K total | ~10 MB |
| `p_future` | ~14.7M | ~1.5 GB |

**Root cause:** Automatic daily `REORGANIZE PARTITION p_future` was disabled after production incidents where metadata locks blocked **all INSERTs for hours/days** while WebSocket markers still moved (empty path symptom).

**Risk for staging rebuild:**
- Restoring a mysqldump may not preserve partition layout correctly
- Running migrations that touch `gps_data` on 18M rows can lock table for extended periods
- `gps:ensure-partitions --force` must **never** run on live or staging with production-scale data without maintenance window

**Safe approach:**
1. Schema-only restore for staging; seed with subset of recent GPS data
2. Accept all new data in `p_future` until dedicated DBA window for partition maintenance
3. Use `WHERE date_time >= CURDATE()` for health checks, never full-table COUNT in automation

### 🔴 Full-table queries on gps_data

**Observed:** `COUNT(*)` on 18M rows took **54 seconds** and stressed I/O.

**Risk:** Deploy scripts, health checks, or monitoring that scan `p_future` can OOM eco1-small VPS (no swap) and drop SSH.

**Mitigation:**
- Always use `gps:ingest-health --fast` in deploy (already in `deploy.sh`)
- Use `information_schema.PARTITIONS.TABLE_ROWS` for estimates
- Add query timeouts for staging

### 🟠 mysql_gps == mysql (same database)

Production uses separate Laravel connections (`mysql`, `mysql_gps`, `mysql_gps_read`) but all point to **`api_db`** on `127.0.0.1`.

**Risk:** Staging docs may assume separate GPS database server; read/write separation is logical only.

**Staging mitigation:** Can use same single DB initially; split to dedicated GPS DB only when scaling requires it.

### 🟠 Unique index on (imei, date_time) may be absent

Migration `2026_07_14_000001` is **no-op by default** unless `GPS_FORCE_UNIQUE_IMEI_DATETIME=true`.

**Risk:** Duplicate rows on gateway replay; staging behavior may differ from production if env flag differs.

**Mitigation:** Document env flag in staging `.env`; do not force unique index without offline dedupe.

### 🟡 Telescope and notifications growth

| Table | Size | Growth |
|---|---:|---|
| telescope_entries | 88 MB | Daily prune (24h) scheduled |
| notifications | 60 MB | Unbounded user notifications |

**Risk:** Staging import of production dump includes debug data; disk fill on small staging VM.

**Mitigation:** Exclude telescope tables from staging import; truncate after restore.

### 🟡 Far-future junk GPS rows

**Observed dates in gps_data:** 2027, 2029, 2032, 2068, etc. (~20 rows in last-14-day grouping but millions may exist in p_future).

**Risk:** Skews retention planning, partition strategy, and metrics.

**Mitigation:** Run `gps:purge-future-junk --dry-run` on staging first; never on production during peak without `--force` review.

---

## 3. GPS Pipeline Risks

### 🟠 Laravel vs Go ingest driver mismatch

| Component | Production state |
|---|---|
| `GPS_INGEST_DRIVER` | `laravel` |
| Go gps-ingest service | **Running** (standby) |
| nginx Go proxy | May or may not be active |

**Risk:** Staging configured for Go while production uses Laravel (or vice versa) → different failure modes, performance, side-effect paths.

**Staging mitigation:**
- Match production driver initially (`laravel`)
- Test Go cutover in isolated staging phase with `nginx-gps-production.conf`

### 🟠 Broadcast before persist ordering

`IngestGpsData` only dispatches `BroadcastGpsEvents` **after** durable write. If staging skips workers or DB fails, mobile shows stale markers from cached WS state.

**Mitigation:** Always verify both `gps-processing` AND `gps-broadcast` workers in staging health gate.

### 🟡 WithoutOverlapping per IMEI

Concurrent batches for same IMEI are released (3s delay), not dropped. Under high gateway replay, staging may show ingest delay spikes.

### 🟡 Go side-effect inbox dependency

When on Go driver, `gps:consume-side-effects` must run for tractor task zone updates. Missing consumer → tasks don't update despite live map.

---

## 4. GIS / PostGIS Migration Strategy

### Current state (MySQL)

| Data | Storage | Spatial index |
|---|---|---|
| Tractor GPS points | `gps_data.coordinate` VARCHAR (JSON `[lat,lon]`) | None |
| Farm/field/plot boundaries | `coordinates` LONGTEXT (JSON polygon arrays) | None |
| Farm/field center | `center` VARCHAR (`"lat,lon"`) | None |
| Valve location | JSON in coordinates column | None |
| Attendance GPS | `attendance_gps_data.coordinate` LONGTEXT | None |

**All geospatial logic is application-level PHP:**
- Point-in-polygon (`TractorTaskService::isPointInTaskZone`)
- Haversine distance (`IngestGpsData`, path services)
- Path correction (Kalman/median filters)

**No MySQL spatial types (`POINT`, `POLYGON`, `GEOMETRY`) or SPATIAL INDEX.**

### Current limitations

1. **No native spatial queries** — cannot efficiently query "all tractors within 500m of point"
2. **JSON coordinate parsing overhead** — every path point decoded in PHP
3. **Polygon storage inconsistent** — mix of JSON arrays, comma-separated centers, nested structures
4. **No SRID / projection metadata** — assumes WGS84 implicitly
5. **Partition + spatial index conflict** — MySQL spatial indexes on partitioned tables have restrictions
6. **18M row table** — migration to geometry column requires full table rewrite

### Recommended PostGIS migration strategy

#### Phase 0 — Preparation (no downtime)

- [ ] Audit all coordinate formats across tables (normalize to WGS84 decimal degrees)
- [ ] Add application abstraction layer (`LocationValue`, `PolygonValue` DTOs)
- [ ] Export farm/field/plot boundaries to GeoJSON for validation
- [ ] Benchmark current path query patterns (by tractor_id + date range)

#### Phase 1 — Dual-write staging (2–4 weeks)

- [ ] Stand up PostgreSQL 16 + PostGIS 3.4 on staging
- [ ] Create schema:

```sql
-- Example target schema
CREATE TABLE gps_points (
    id BIGSERIAL,
    tractor_id BIGINT NOT NULL,
    imei VARCHAR(20),
    geom GEOMETRY(POINT, 4326) NOT NULL,
    speed INTEGER,
    status SMALLINT,
    recorded_at TIMESTAMPTZ NOT NULL,
    PRIMARY KEY (id, recorded_at)
) PARTITION BY RANGE (recorded_at);

CREATE INDEX idx_gps_points_tractor_time ON gps_points (tractor_id, recorded_at);
CREATE INDEX idx_gps_points_geom ON gps_points USING GIST (geom);

CREATE TABLE farm_boundaries (
    farm_id BIGINT PRIMARY KEY,
    geom GEOMETRY(POLYGON, 4326) NOT NULL
);
CREATE INDEX idx_farm_boundaries_geom ON farm_boundaries USING GIST (geom);
```

- [ ] Laravel: add `pgsql_gps` connection; dual-write in `IngestGpsData` behind feature flag
- [ ] Validate row counts and coordinate accuracy between MySQL and PG

#### Phase 2 — Read path cutover (staging)

- [ ] Migrate `TractorPathStreamService` to read from PostGIS
- [ ] Rewrite zone detection using `ST_Contains(geom, ST_SetSRID(ST_MakePoint(lon, lat), 4326))`
- [ ] Performance test: path stream for 1 day / 10K points / 50 concurrent users

#### Phase 3 — Historical migration (maintenance window)

- [ ] Batch migrate `gps_data` → `gps_points` (100K rows/batch, ordered by date_time)
- [ ] Migrate farm/field/plot polygons with `ST_GeomFromGeoJSON`
- [ ] Verify counts: MySQL vs PG per tractor per day
- [ ] Keep MySQL gps_data read-only for rollback period (30 days)

#### Phase 4 — Production cutover

- [ ] Stop GPS ingest briefly (< 30s) or use dual-write buffer
- [ ] Switch `DB_GPS_*` to PostgreSQL
- [ ] Retire MySQL gps_data writes
- [ ] Update backup scripts for PG

#### Phase 5 — Decommission MySQL GPS

- [ ] Archive MySQL gps_data to cold storage (Parquet/CSV by month)
- [ ] Drop gps_data from MySQL after validation period
- [ ] Reclaim ~3.6 GB disk

### PostGIS advantages for PiStat

| Capability | MySQL today | PostGIS |
|---|---|---|
| Point-in-polygon | PHP loop | `ST_Contains` (indexed) |
| Nearest tractor | Haversine in PHP | `ST_DWithin` + KNN |
| Path length | Manual | `ST_Length(ST_MakeLine(...))` |
| Simplify paths | Custom Kalman | `ST_Simplify` |
| Cross-farm analytics | Full table scan | Spatial index scan |
| Time-series + space | Partition only | Partition + GiST |

### Migration risks specific to PostGIS

| Risk | Mitigation |
|---|---|
| 18M row migration duration | Batch with COPY; parallel workers; off-peak |
| Laravel MySQL-specific queries | Audit all raw SQL; use Eloquent PG grammar |
| Jalali date in WS payloads | Keep presentation layer unchanged for mobile |
| Dual DB operational complexity | Phase dual-write; single write path before prod |
| Team PostgreSQL ops maturity | Include PG backup/PITR in staging runbook first |

---

## 5. Security Risks (Staging Must Not Replicate)

| Issue | Production state | Staging requirement |
|---|---|---|
| `.env` mode 755, world-readable | 🔴 Yes | `chmod 640`, owner `api:api` |
| `APP_DEBUG=true` | 🔴 Yes | `false` on staging |
| `TELESCOPE_ENABLED=true` | 🟠 Yes | `false` or auth-gated |
| MySQL `bind-address=0.0.0.0` | 🔴 Yes | `127.0.0.1` only |
| SSH password auth | 🟠 Yes | Key-only auth |
| `storage/` mode 777 | 🟡 Yes | `775` max |
| Reverb origins `*` | 🟡 Yes | Restrict to staging app origin |

---

## 6. Performance & Capacity Risks

### 🔴 Disk 89% full

**Risk:** MySQL temp tables, binlog, GPS growth, backups fail.

**Staging:** Monitor disk; size staging VPS ≥ 100 GB; implement GPS retention policy before mirroring full prod data.

### 🟠 No swap configured

**Risk:** Large queries (GPS COUNT, partition ops, Telescope) trigger OOM killer.

**Staging:** Add 2–4 GB swap; set `innodb_buffer_pool_size` appropriately (~50% RAM for dedicated DB).

### 🟡 Daily GPS ingest rate

**Observed:** 4,000–10,000 rows/day (~5–7 MB/day growth).

**Projection:** ~2.5 GB/year at current fleet size. Scale with device count.

**Retention recommendation:**
- Hot: 90 days in primary DB
- Warm: 1 year in partitioned/archive table
- Cold: S3/Parquet beyond 1 year

---

## 7. Deployment & Rollback Risks

### 🟠 queue:restart during GPS replay

Workers cycle STARTING→RUNNING; gateway replay during this window queues backlog.

**Mitigation:** Run deploy outside peak hours; wait `WORKER_SETTLE_SECONDS` (6s + retries) per `deploy.sh`.

### 🟠 Go cutover rollback

Documented in `docs/gps-ingest-rollback-runbook.md`:
1. Comment nginx Go proxy block
2. Set `GPS_INGEST_DRIVER=laravel`
3. Start Laravel GPS workers
4. Stop Go service

**Staging:** Practice rollback before production Go cutover.

### 🟡 Git reset --hard deploy

`deploy.sh` uses hard reset — local server changes lost.

**Staging:** Use same deploy script for parity; avoid manual hotfixes on server.

---

## 8. Staging Rebuild Priority Order

| Priority | Action | Risk if skipped |
|---|---|---|
| P0 | Fix path unification (single public_html) | Wrong code version running |
| P0 | Secure `.env`, disable debug/telescope | Credential leak |
| P0 | Bind MySQL to localhost | DB exposure |
| P1 | Install supervisor GPS workers (4/2/2/1) | No GPS functionality |
| P1 | Fix scheduler cron path | Missing daily jobs |
| P1 | Schema restore from `DATABASE_SCHEMA_REFERENCE.md` | Schema drift |
| P2 | Import subset GPS data (last 30 days) | Untestable path API |
| P2 | Configure Reverb on staging WS domain | No live map |
| P2 | Match `GPS_INGEST_DRIVER` to prod | Wrong ingest behavior |
| P3 | Go ingest shadow testing | Cutover surprise |
| P3 | PostGIS staging instance | GIS feature delay |

---

## 9. Pre-Migration Validation Checklist

Before promoting staging changes to production:

- [ ] `php artisan gps:ingest-health --fast` passes
- [ ] All 4 GPS supervisor groups RUNNING
- [ ] `redis-cli LLEN queues:gps-processing` = 0 after test ingest
- [ ] Test POST `/api/gps/reports` from allowlisted IP returns 200
- [ ] WS `report-received` received on mobile test build
- [ ] `SELECT COUNT(*) FROM gps_data WHERE tractor_id=X AND date_time >= CURDATE()` > 0 after test
- [ ] `./deploy.sh` exits 0
- [ ] No `APP_DEBUG`, no world-readable `.env`
- [ ] Backup restore tested (`/opt/pistat-db-backup.sh` equivalent on staging)

---

## 10. Slow Query / Index Risk Summary

| Query pattern | Table | Index used | Risk |
|---|---|---|---|
| Path by tractor + date | gps_data | `gps_data_tractor_id_date_time_index` | Low if date bounded |
| IMEI lookup | gps_data | `gps_data_imei_index` | Medium on wide date range |
| Start time detection | gps_data | `idx_gps_data_start_time_detection` | Low |
| Full partition scan | gps_data | None | **Critical** — avoid |
| Unbound IMEI check | gps_devices | `gps_devices_imei_unique` | Low |
| Task zone (PHP polygon) | fields/plots | None (app-level) | CPU bound |

**Missing indexes for future GIS (PostGIS):**
- GiST on point geometry
- GiST on farm/field polygons
- Composite (tractor_id, recorded_at DESC) for latest-position queries

---

*Generated by production architecture audit — read-only, 2026-08-04*
