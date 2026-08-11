# PiStat Production — Disk Capacity Audit Report

**Host:** `ubuntu-eco1-small1-bamdad-1`  
**Audit date:** 2026-08-05  
**Mode:** READ-ONLY — no deletes, truncates, prune, optimize, migrate, or service changes  
**Constraint honored:** Do not recommend deleting GPS history, `gps_data`, or vital DB content as an immediate action  

---

## 1. Executive Summary

Root filesystem `/dev/vda3` is **66 GB, 89% used (56 GB / 7.0 GB free)**. Inodes are healthy (**2% used**). Swap is **absent**.

Space is dominated by three buckets:

| Rank | Consumer | On-disk size | Nature |
|---:|---|---:|---|
| 1 | MariaDB datadir `/var/lib/mysql` | **~21 GB** | Mostly `api_db`; **~15 GB is Telescope InnoDB tablespace bloat** (logical data ~72 MB) |
| 2 | Local backups `/var/backups/pistat` | **~19 GB** | 96 DB dumps + 20 weekly app archives; **retention hostname mismatch** prevents cleanup |
| 3 | System journals `/var/log/journal` | **~2.2 GB** | No `SystemMaxUse` cap |
| — | Extra uncompressed SQL dumps | **~4.0 GB** | `/home/api/backups` + DirectAdmin `custombuild/mysql_backups` |
| — | Live `gps_data` (logical) | **~3.6 GB** (**~4.5 GB** `p_future.ibd`) | **Must keep** — not a cleanup target |

**Root cause of “disk full”:** not GPS growth alone (~5–7 MB/day). Primary drivers are **Telescope `.ibd` bloat (~15 GB)** and **orphaned local backups (~17 GB older than 30 days)** due to a backup retention script naming bug.

**Docker:** empty (0 images/containers/volumes) — not a factor.

**Immediate safe headroom (without deleting GPS/history):** reclaim **journal + orphan temps + apt + broken gps_lag log growth control** (~2–2.5 GB), then plan **Telescope tablespace reclaim** and **off-box backup retention** for the large gains.

---

## 2. Current Disk Status

### `df -h`

| Filesystem | Size | Used | Avail | Use% | Mount |
|---|---:|---:|---:|---:|---|
| `/dev/vda3` | **66G** | **56G** | **7.0G** | **89%** | `/` |
| `/dev/vda1` | 549M | 304K | 549M | 1% | `/boot/efi` |
| tmpfs (several) | — | minimal | — | ≤1% | `/run`, `/dev/shm`, … |

**Filled filesystem:** `/` on `/dev/vda3` only.

### `df -i`

| Filesystem | Inodes | IUsed | IUse% |
|---|---:|---:|---:|
| `/dev/vda3` | 17,373,888 | 198,958 | **2%** |

**Inode exhaustion:** **No.**

### Memory / Swap

| Metric | Value |
|---|---|
| RAM | 7.8 Gi total; ~5.2 Gi available |
| Swap | **0B** (`swapon --show` empty) |

Swap creation is **out of scope** for this audit (documented only; not created).

### Temp / coredump

| Path | Size | Notes |
|---|---:|---|
| `/tmp` | ~128K | Negligible |
| `/var/tmp` | ~52–96K | Negligible |
| `/var/lib/systemd/coredump` | 4.0K | Empty — no crash dumps retained |

---

## 3. Top Space Consumers

### Top-level (`du -xhd1 /`)

| Path | Size |
|---|---:|
| `/var` | **43G** |
| `/usr` | 7.0G |
| `/home` | 6.5G |
| `/boot` | 117M |
| `/opt` | 44K |
| **Total /** | **56G** |

### `/var` breakdown

| Path | Size |
|---|---:|
| `/var/lib` | **21G** (almost all MySQL) |
| `/var/backups` | **19G** (all under `pistat/`) |
| `/var/log` | **2.7G** |
| `/var/cache` | 183M |
| `/var/www` | 130M |

### Largest concrete objects (priority order)

| Path | Size | Category |
|---|---:|---|
| `/var/lib/mysql/api_db/telescope_entries.ibd` | **~14.7G** | InnoDB file bloat (logical ~72 MB) |
| `/var/backups/pistat/` (aggregate) | **~19G** | Local backups |
| `/var/lib/mysql/api_db/gps_data#P#p_future.ibd` | **~4.5G** | Live GPS (keep) |
| `/usr/local/directadmin/custombuild/mysql_backups/api_db.sql` | **~2.2G** | Uncompressed DA dump (2026-05-02) |
| `/var/log/journal` | **~2.2G** | systemd journals |
| `/home/api/backups/backup/api_db.sql` | **~1.7G** | Uncompressed dump (2026-05-05) |
| `/home/ubuntu/.cursor-server` | **~1.2G** | IDE remote tooling |
| `/var/lib/mysql/api_db/failed_jobs.ibd` | **~0.33G** | Bloat (27 live rows / ~0.25 MB logical) |
| `/var/lib/redis/temp-2499188.rdb` | **~237M** | Orphan Redis temp (2026-04-17) |
| App tree `/home/api/domains/.../public_html` | **~644–658M** | Code + vendor + storage |

> Full `du -xah / | sort | head` was approximated via targeted `du`/`find` of largest trees to avoid multi-hour full-root sort under load. Coverage of >95% of used space is accounted above.

---

## 4. Docker Storage Analysis

| Check | Result |
|---|---|
| `docker system df` | Images/Containers/Volumes/Build Cache all **0B** |
| `docker images` | Empty |
| `docker ps -a --size` | No containers |
| `/var/lib/docker` | **~212K** |

**Conclusion:** Docker is installed but unused. **No reclaimable Docker space.** No prune recommended or performed.

---

## 5. Laravel Storage Analysis

**App path:** `/home/api/public_html` → symlink → `/home/api/domains/api.pistatapp.ir/public_html`

| Path | Size |
|---|---:|
| App root | ~644M |
| `vendor/` | **288M** |
| `storage/` | **321M** |
| `storage/app` | **307M** (almost all `public/application-zip` APK releases) |
| `storage/logs` | **13M** |
| `bootstrap/cache` | **492K** |

### Application logs

| File | Size | Notes |
|---|---:|---|
| `storage/logs/laravel.log` | **12M** | Single file; last write 2026-08-04 |
| `gps-processing.log` | 740K | Supervisor stdout |
| `gps-broadcast.log` | 368K | |
| `worker.log` | 240K | |
| Other GPS/reverb logs | &lt;200K each | |
| **Log file count** | **8 files / 7 `*.log`** | No daily-rotated `laravel-YYYY-MM-DD.log` |

**Laravel log rotation:** No dedicated `/etc/logrotate.d` entry for `storage/logs`. Supervisor confs set `stdout_logfile_maxbytes=50MB` for GPS workers. `laravel.log` itself is **not** actively rotated by logrotate.

**Telescope (DB, not files):** logical ~72 MB rows, but **on-disk `.ibd` ~14.7 GB** — primary MariaDB bloat (see §6). `TELESCOPE_ENABLED=true` in production.

**APK releases:** ~307M under `storage/app/public/application-zip/` (multiple dated builds). Application artifacts, not GPS logs.

---

## 6. MariaDB Storage Analysis

### Schema totals (`information_schema`)

| Schema | Total GB | Data GB | Index GB |
|---|---:|---:|---:|
| `api_db` | **3.76** | 1.58 | 2.17 |
| Others (`mysql`, `sys`, …) | ≈0 | — | — |

### Datadir reality (`/var/lib/mysql`)

| Path | Size |
|---|---:|
| `/var/lib/mysql` | **21G** |
| `/var/lib/mysql/api_db` | **20G** |

**Gap explained:** InnoDB file sizes ≫ `information_schema` after mass DELETEs (Telescope prune) without tablespace reclaim.

### `gps_data` (KEEP — do not delete/archive in capacity actions)

| Metric | Value |
|---|---|
| Logical data | **1506.58 MB** |
| Logical indexes | **2187.98 MB** |
| Logical total | **3694.56 MB (~3.61 GB)** |
| Approx rows | **~14.7M** (`information_schema`) |
| On-disk `p_future.ibd` | **~4.47 GB** |
| Daily partitions | 7× ~few MB each (Oct 2025) |

Partition layout unchanged from Phase 12: nearly all rows in `p_future`.

### Telescope / failed_jobs file vs logical

| Table | Logical (data+idx) | Rows | On-disk `.ibd` | Bloat? |
|---|---:|---:|---:|---|
| `telescope_entries` | ~72 MB | ~111K | **~14.68 GB** | **Yes (~14.6 GB)** |
| `telescope_entries_tags` | ~5 MB | ~8K | **~0.18 GB** | Yes |
| `failed_jobs` | ~0.25 MB | **27** | **~0.33 GB** | Yes |
| `notifications` | ~60 MB | ~39K | ~0.07 GB | Mild |

### Binary logs

| Variable | Value |
|---|---|
| `log_bin` | **OFF** |
| `expire_logs_days` / `binlog_expire_logs_seconds` | 0 / 0 |
| Binlog disk usage | **None** (binary logging disabled) |

`SHOW BINARY LOGS` denied to app user; `log_bin=OFF` is sufficient confirmation.

---

## 7. Log Retention Analysis

| Source | Size | Retention / notes |
|---|---:|---|
| `journalctl` / `/var/log/journal` | **2.1–2.2G** | `SystemMaxUse` **unset** → unbounded growth |
| `syslog` (+rotated) | ~28M + 51M + gz | rsyslog logrotate: weekly, rotate 4, compress |
| `auth.log` (+rotated) | ~12M + 17M + gz | Same rsyslog policy |
| `kern.log` | ~17M + rotations | Same |
| `/var/log/supervisor` | **33M** | Mostly `supervisord.log` |
| `/var/log/nginx` | **~10M** | Modest |
| `/var/log/httpd` | **31M** | DirectAdmin Apache |
| `/var/log/gps_lag.log` | **57M** | Cron every minute; **no rotation**; script queries obsolete `gps_reports` table |
| `/var/log/btmp` (+`.1`) | ~9M + 45M | Failed logins |
| Laravel `storage/logs` | 13M | No logrotate; low urgency |
| MariaDB error log path | Not under `/var/log/mysql` | N/A in this layout |

**GPS application logs are small (≪100M total).** They are **not** the disk crisis and must not be wiped for capacity.

---

## 8. Backup Analysis

### `/var/backups/pistat` — **19G**, **116 files**

| Type | Count | Aggregate size |
|---|---:|---:|
| DB dumps `server-db-api_db-*.sql.gz` | **96** | **~16G** |
| Weekly app `*app-weekly*.tar.gz` | **20** | **~3.1G** |

| Age bucket | Approx size |
|---|---:|
| Last 14 days | **~0.5G** |
| Older than 30 days | **~17.0G** |
| Older than 90 days | **~15.7G** |

Largest single gz dumps ≈ **340–425 MB** (under 500M threshold).  
`find … -size +500M` hits were **uncompressed** `.sql` only:

| Path | Size | Date |
|---|---:|---|
| `/usr/local/directadmin/custombuild/mysql_backups/api_db.sql` | **~2.2G** | 2026-05-02 |
| `/home/api/backups/backup/api_db.sql` | **~1.7G** | 2026-05-05 |

### Retention bug (critical finding)

Scripts `/opt/pistat-db-backup.sh` and `/opt/pistat-weekly-full.sh`:

- Write/upload to remotes (`rclone` → Arvan + ParsPack) ✅  
- Local delete patterns use **`$(hostname -s)-db-…`** / **`$(hostname -s)-app-weekly-…`**  
- Current hostname: `ubuntu-eco1-small1-bamdad-1`  
- Existing files mostly named **`server-db-…`** / **`server-app-weekly-…`**  
- Match count for `${HOSTNAME}-db-*.sql.gz`: **0**  
- Therefore **old local dumps never expire** despite “14 day / 30 day” policy.

**This audit does not delete backups.** Safe path: verify remote copies, then apply a planned offload/retention fix (P1).

### `/home/api/backups` — **4.1G**

Includes many `.tar.zst` (2025–early 2026) plus uncompressed `backup/api_db.sql` (~1.7G).

---

## 9. Safe Cleanup Opportunities

Ranked by **safety first**. Items that touch GPS history or live `gps_data` are **excluded** from “safe/immediate”.

### Opportunity matrix

| ID | Opportunity | Est. free | Deletes historical GPS? | Deletes backups? | Downtime? |
|---|---|---:|---|---|---|
| A | Cap systemd journal (`SystemMaxUse=200M` + vacuum) | **~1.5–2.0G** | No | No | No |
| B | Stop/fix `gps_lag_check.sh` unbounded log (after rotate/archive log) | **~57M+ ongoing** | No | No | No |
| C | Remove orphan Redis temp `temp-2499188.rdb` (after confirm not in use) | **~237M** | No | No | No |
| D | `apt-get clean` (package cache only) | **~100–140M** | No | No | No |
| E | Disable Telescope in prod → then **maintenance** `OPTIMIZE`/`ALTER … ENGINE=InnoDB` on telescope tables + `failed_jobs` | **~14.5–15G** | No (debug telemetry only) | No | **Yes (table locks; schedule window)** |
| F | After **remote verify**, move/age local `/var/backups/pistat` files matching old `server-*` names; fix retention globs | **up to ~17G** | No | Local copies only (keep remotes) | No |
| G | Compress/relocate DA + `/home/api/backups` uncompressed `.sql` after remote verify | **~3.5–4G** | No | Local dumps only | No |
| H | Expand disk volume to ≥100–150G | Headroom | No | No | Brief resize window possible |
| I | Add swap 2–4G | Stability (not free space) | No | No | No |
| ❌ | Delete/truncate `gps_data` / GPS logs / purge partitions | — | **Forbidden** | — | — |
| ❌ | `docker prune` | 0B anyway | — | — | — |

---

## 10. Recommended Actions (Ranked by Safety)

### Priority P0 — No historical-data deletion; can plan immediately

| Action | Frees | Risk | Historical GPS deleted? | Downtime |
|---|---|---|---|---|
| **P0.1** Set `SystemMaxUse` (e.g. 200–500M) in journald and vacuum to cap | ~1.5–2.0G | Low — loses old OS journal text only | **No** | **No** |
| **P0.2** Fix or disable broken `gps_lag_check.sh`; introduce logrotate for `/var/log/gps_lag.log` | 57M + stop growth | Low | **No** | **No** |
| **P0.3** Confirm Redis orphan temp RDB unused, then remove in a later change window | ~237M | Low if confirmed idle | **No** | **No** |
| **P0.4** APT cache clean | ~100–140M | Very low | **No** | **No** |
| **P0.5** Document & ticket: **do not** run deep GPS COUNT / `ensure-partitions` while disk &lt;15% free | Prevents outage | — | **No** | — |

*This audit applied none of the above.*

### Priority P1 — Requires planning / verification

| Action | Frees | Risk | Historical GPS deleted? | Downtime |
|---|---|---|---|---|
| **P1.1** Verify rclone remotes hold dumps → relocate or expire **local** `server-db-*` / old weekly archives &gt;30d; **fix hostname glob** in backup scripts | up to **~17G** | Medium — must verify remote integrity first | **No** | **No** |
| **P1.2** Disable `TELESCOPE_ENABLED` / stop recording → maintenance window `OPTIMIZE TABLE` (or recreate tablespace) for `telescope_entries`, `telescope_entries_tags`, `failed_jobs` | **~15G** | Medium — long table lock / I/O on small VPS; take backup first | **No** | **Yes** (minutes–hours depending on method) |
| **P1.3** Handle uncompressed DA/`/home/api/backups` `.sql` (compress + off-host, or delete local after remote checksum) | **~4G** | Medium — confirm not sole copy | **No** | **No** |
| **P1.4** Add logrotate for Laravel `storage/logs` + supervisor `supervisord.log` | tens of MB | Low | **No** (GPS logs retained via rotation, not wipe) | **No** |
| **P1.5** Create 2–4G swap file | 0 free space; prevents OOM | Low–medium | **No** | **No** |

### Priority P2 — Long-term optimization

| Action | Frees / benefit | Risk | Historical GPS deleted? | Downtime |
|---|---|---|---|---|
| **P2.1** Expand root volume to **≥100–150 GB** | Sustainable headroom | Low (provider resize) | **No** | Possible brief |
| **P2.2** GPS retention architecture (hot 90d / cold object storage) — **design only until approved** | Future GB/year | High if done wrong | Only with explicit policy | Planned |
| **P2.3** Rebuild partitions under DBA window (not for free space primarily) | Query health | **Critical** if online | No | **Yes** |
| **P2.4** Move backups to dedicated volume / remote-only local retention (3–7 days) | Keeps `/` clean | Low | No | No |
| **P2.5** Keep APK release retention policy (keep N latest under `application-zip`) | ~100–200M | Low | No | No |

---

## 11. What NOT to Do

- Do **not** delete, truncate, or archive **`gps_data`** / GPS historical rows for disk relief.  
- Do **not** delete GPS worker logs as a primary strategy (they are tiny).  
- Do **not** run `OPTIMIZE`/`ALTER` on `gps_data` for capacity.  
- Do **not** `docker system prune` (nothing to reclaim; risk if misused later).  
- Do **not** delete `/var/backups/pistat` until **remote copies are verified**.  
- Do **not** enable binary logs without retention — currently OFF (good for disk).

---

## 12. Capacity Projection (if no action)

| Driver | Trend |
|---|---|
| `gps_data` | ~5–7 MB/day logical (~2–2.5 GB/year) — sustainable only with free headroom |
| Telescope bloat | Already **~15G**; may grow again if Telescope stays enabled without periodic reclaim |
| Backups | +~0.5–1G/week local if retention bug persists |
| Journals / gps_lag.log | Continues until capped |

**At 89% with 7 GB free:** one failed large dump or Telescope spike can exhaust the volume.

---

## 13. Suggested Order of Operations (Future Change Window)

1. **P0** journal cap + gps_lag fix + apt clean → quick ~2G.  
2. **P1.1** verify remotes → age local `server-*` backups → fix script globs → up to ~17G.  
3. **P1.2** disable Telescope → optimize bloated tables → ~15G.  
4. **P2.1** expand disk + add swap.  
5. Re-run `df -h` gate: target **&lt;70%** before any migration work.

---

## 14. Audit Completeness Checklist

| Section | Status |
|---|---|
| Disk / inodes | Done |
| Top consumers | Done |
| Docker | Done (empty) |
| Laravel storage/logs | Done |
| Supervisor / system logs | Done |
| MariaDB / gps_data / binlog | Done |
| Backups &gt;500M + inventory | Done |
| `/tmp`, coredump | Done |
| Memory/swap (report only) | Done |
| Changes applied | **None** |

---

*Disk capacity audit — read-only, 2026-08-05. No files, databases, services, or backups were modified.*
