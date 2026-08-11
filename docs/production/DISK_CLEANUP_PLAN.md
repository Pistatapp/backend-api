# PiStat Production — Disk Cleanup Plan

**Host:** `ubuntu-eco1-small1-bamdad-1`  
**Date:** 2026-08-05  
**Mode:** READ-ONLY investigation — **no cleanup executed**  
**Related:** `docs/production-migration/DISK_CAPACITY_AUDIT_REPORT.md`

---

## Status Snapshot

| Metric | Value |
|---|---|
| Root `/dev/vda3` | **66G**, **56G used (89%)**, **7.0G free** |
| Inodes | **2%** used — not exhausted |
| Swap | **None** |
| Docker reclaimable | **0B** (no images/containers/volumes) |

### Space map (approx.)

| Area | Size | Notes |
|---|---:|---|
| `/var` | 43G | MySQL + backups + logs |
| `/var/lib/mysql` | 21G | `api_db` datadir |
| `/var/backups/pistat` | 19G | Local backup pile |
| `/home` | 6.5G | App + old user backups + Cursor tooling |
| `/usr` | 7.0G | DirectAdmin / MariaDB / PHP packages |
| `/var/log` | 2.7G | Mostly journal |

---

## Investigation Findings

### 1. Laravel Telescope growth

| Object | Logical size | On-disk file | Rows |
|---|---:|---:|---:|
| Table `api_db.telescope_entries` | **~72 MB** | `/var/lib/mysql/api_db/telescope_entries.ibd` **~15G** | ~111K |
| Table `api_db.telescope_entries_tags` | **~5 MB** | `…/telescope_entries_tags.ibd` **~180M** | ~8K |

**Cause:** Production has Telescope enabled; daily prune deletes **rows** but does **not** shrink InnoDB tablespace → classic `.ibd` bloat.

**Impact:** Largest single reclaimable MySQL object (~14.6G wasted).

### 2. Backup retention failure

| Location | Size | Files |
|---|---:|---:|
| `/var/backups/pistat` | **19G** | 116 |
| Age `mtime +14` | **~17.6G** | Should have been purged by DB script policy |
| Age `mtime +30` | **~17.0G** | Should have been purged by weekly policy |

**Root cause:** Scripts delete only names matching **`$(hostname -s)-…`**:

- Current host: `ubuntu-eco1-small1-bamdad-1`
- Matching `${HOST}-db-*.sql.gz` count: **0**
- Legacy/orphan pattern `server-db-*.sql.gz` count: **96** (~16G)
- Weekly archives also include old `server-app-weekly-*.tar.gz` names

Scripts already upload via `rclone` to Arvan + ParsPack; local retention never matches legacy filenames.

### 3. systemd journal size

| Path | Size |
|---|---:|
| `/var/log/journal` (journalctl disk usage) | **~2.2G** |
| `SystemMaxUse` in `journald.conf` | **Unset** (unbounded) |

### 4. `gps_data` growth

| Metric | Value |
|---|---|
| Logical data+index | **~3695 MB (~3.61G)** |
| Approx rows | **~14.7M** |
| On-disk `gps_data#P#p_future.ibd` | **~4.5G** |
| Growth rate (prior audit) | ~5–7 MB/day |
| Binary logs | **`log_bin=OFF`** |

**Conclusion:** GPS table is large but legitimate production data. **Not a cleanup candidate.**

### 5. GPS-related logs

| Path | Size | Issue |
|---|---:|---|
| `/var/log/gps_lag.log` | **57M** | Cron every minute; **no rotation**; script references obsolete `gps_reports` |
| Laravel `storage/logs/*.log` (GPS workers) | **&lt;2M** combined | Healthy; Supervisor `maxbytes=50MB` |
| `storage/logs/laravel.log` | **~12M** | No dedicated logrotate |

### 6. Other notable consumers

| Path / object | Size | Class |
|---|---:|---|
| `/home/api/backups/backup/api_db.sql` | **~1.7G** | Abandoned uncompressed dump (2026-05-05) |
| `/usr/local/directadmin/custombuild/mysql_backups/api_db.sql` | **~2.2G** | Abandoned DA dump (2026-05-02) |
| `/home/api/backups/*.tar.zst` (many) | ~2.4G+ | Older app backups |
| `/var/lib/mysql/api_db/failed_jobs.ibd` | **~340M** | Bloat (27 rows / ~0.25 MB logical) |
| `/var/lib/redis/temp-2499188.rdb` | **237M** | Orphan temp (2026-04-17) |
| Laravel `storage/app/public/application-zip` | **307M** | APK releases (product artifacts) |
| `/home/ubuntu/.cursor-server` | **~1.2G** | IDE tooling |
| Docker | **0** | N/A |

---

## Cleanup Catalog

For each candidate: path/table, size, risk, expected free space, rollback.

### A. Safe cleanup (low risk — still requires explicit approval before execute)

> Do **not** disable monitoring here without separate approval. Journal vacuum reduces OS log history only.

| ID | Exact path / target | Current size | Risk | Expected free | Rollback |
|---|---|---:|---|---:|---|
| S1 | systemd journal (`/var/log/journal`) via `journalctl --vacuum-size=200M` **after** setting `SystemMaxUse` | ~2.2G | **Low** — loses old OS journal text | **~1.5–2.0G** | **No** (journals gone); config reversible |
| S2 | `/var/log/gps_lag.log` — rotate/compress once + add logrotate; fix or pause broken cron script **only with approval** (touches monitoring) | 57M + growth | **Low–Med** if script change needs approval | **~57M** + stop growth | Keep rotated copy off-box; restore script from git/`/opt` backup |
| S3 | APT cache `/var/cache/apt/archives` (`apt-get clean`) | ~100–140M | **Very low** | **~100–140M** | Re-download packages |
| S4 | `/var/lib/redis/temp-2499188.rdb` after confirming Redis not using it | 237M | **Low** if idle since Apr 2026 | **~237M** | **No** (temp file); Redis RDB `dump.rdb` remains |
| S5 | `/var/log/btmp.1` (old failed-login wtmp) if policy allows | 45M | **Low** | **~45M** | **No** (auth failure history) |

**Combined S1–S5 without touching backups/DB:** ~**2.0–2.5G**

---

### B. Requires maintenance window / verification

| ID | Exact path / table | Current size | Risk | Expected free | Rollback |
|---|---|---:|---|---:|---|
| M1 | Local backups `/var/backups/pistat/server-db-*.sql.gz` older than policy (**after rclone remote verify**) | ~16G of ~19G tree | **Medium** — local only; remotes must be checked first | **up to ~16–17G** | Restore from Arvan/ParsPack rclone remotes |
| M2 | Local `/var/backups/pistat/*app-weekly*.tar.gz` older than 30d (**after remote verify**) | ~3G subset of tree | **Medium** | **up to ~2–3G** | Restore from remotes |
| M3 | Fix retention globs in `/opt/pistat-db-backup.sh` + `/opt/pistat-weekly-full.sh` to include legacy `server-*` **or** rename files to `${HOST}-*` | N/A (prevents recurrence) | **Low–Med** (script change) | Prevents re-fill | Revert script from copy |
| M4 | `/usr/local/directadmin/custombuild/mysql_backups/api_db.sql` after checksum/off-host copy | **2.2G** | **Medium** | **~2.2G** | Keep off-host copy |
| M5 | `/home/api/backups/backup/api_db.sql` + aged `/home/api/backups/*.tar.zst` after verify | **~4.1G** total dir | **Medium** | **~3–4G** | Off-host restore |
| M6 | `OPTIMIZE TABLE api_db.telescope_entries` (or rebuild tablespace) **during window**; preferably after disabling Telescope recording (separate approval) | File **~15G** / logical ~72M | **High** — long lock, heavy I/O, risk on 7G free disk (need temp space!) | **~14.5–14.8G** | Restore table from backup; keep dump before optimize |
| M7 | `OPTIMIZE TABLE api_db.telescope_entries_tags` | File **~180M** | **Med–High** (same caveats) | **~170M** | Backup restore |
| M8 | `OPTIMIZE TABLE api_db.failed_jobs` | File **~340M** / 27 rows | **Med** | **~330M** | Backup restore |
| M9 | Disk volume expand to ≥100–150G **before** large OPTIMIZE | 66G FS | **Low** (provider) | Headroom | N/A |
| M10 | Add 2–4G swap (stability, not free space) | 0 | **Low–Med** | 0 free | Remove swapfile |

**Critical sequencing note for M6–M8:** With only **7G free**, optimizing a **15G** `.ibd` may need substantial temporary space. **Expand disk (M9) first**, or use `pt-online-schema-change` / export-import strategy designed for low free space. Do **not** run blind `OPTIMIZE` at 89% full.

---

### C. Must not touch

| ID | Exact path / table | Current size | Why forbidden |
|---|---|---:|---|
| X1 | `api_db.gps_data` (+ all partitions / `gps_data#P#p_future.ibd`) | Logical ~3.6G / file ~4.5G | Live GPS history — product data |
| X2 | Laravel GPS worker logs under `storage/logs/gps-*.log` | &lt;2M | Operational forensics; tiny |
| X3 | Active Redis `dump.rdb` / queue keys | — | GPS ingest queues |
| X4 | `/home/api/domains/api.pistatapp.ir/public_html` app code + `vendor` | ~644M | Production application |
| X5 | MariaDB system schemas / `ibdata1` wholesale delete | — | Corruption risk |
| X6 | Current remote-only-unverified backups | — | Sole recovery path until verified |
| X7 | Docker prune | 0B | No benefit; habit risk |
| X8 | `gps:ensure-partitions` / partition REORGANIZE | — | Known multi-hour insert lock risk |
| X9 | Truncate/delete GPS historical rows for disk | — | Explicit business forbid |
| X10 | Disable production monitoring (`gps_lag_check`, NOC, etc.) | — | **Requires separate approval** per rules |

---

## Recommended Execution Order (when approved)

```
Phase 0 (prep)     Expand disk if possible → verify rclone remotes
Phase 1 (safe)     S1 journal cap → S3 apt clean → S4 redis temp → S2 gps_lag (w/ approval)
Phase 2 (backups)  M1–M5 offload/expire local orphans → M3 fix retention scripts
Phase 3 (DB bloat) Disable Telescope recording (approval) → M6–M8 optimize with free-space headroom
Phase 4 (hygiene)  M10 swap → logrotate Laravel/supervisor → APK retention policy
```

**Target gate:** root usage **&lt;70%** before migration work.

---

## Expected Outcomes (if all Safe + verified Backup + Telescope reclaim succeed)

| Bundle | Approx freed |
|---|---:|
| Safe only (S1–S5) | **~2–2.5G** → ~84–86% still tight |
| + Verified backup aging (M1–M5) | **+~20–23G** → comfortable |
| + Telescope/failed_jobs reclaim (M6–M8) | **+~15G** → ample |
| **Theoretical total** | **~35–40G** without touching `gps_data` |

---

## Per-Item Decision Checklist (for change ticket)

Before any delete/optimize, record:

- [ ] Exact path/table listed above  
- [ ] Size re-checked with `du`/`ls` same day  
- [ ] Risk accepted by owner  
- [ ] Remote/off-host copy verified (for backups)  
- [ ] Free space ≥ required temp for OPTIMIZE  
- [ ] Rollback path tested or documented  
- [ ] Maintenance window booked (for section B)  
- [ ] Monitoring-disable approval (if touching gps_lag cron)

---

## Explicit Non-Actions Taken in This Phase

- No files deleted  
- No database modified / optimized  
- No services restarted  
- No Laravel/GPS config changed  
- No monitoring disabled  
- No Docker prune  

**This document is a plan only.**

---

*PiStat disk cleanup plan — read-only, 2026-08-05*
