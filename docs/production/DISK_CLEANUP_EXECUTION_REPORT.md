# PiStat Production — Disk Cleanup Execution Report

**Host:** `ubuntu-eco1-small1-bamdad-1`  
**Executed:** 2026-08-05 (~14:35–14:37 +0330)  
**Scope:** Telescope disable + reclaim, backups &gt;60d retention, journal vacuum  
**Not in scope:** migrations, deploys, GPS/app code, Redis, uploads, `gps_data`

---

## 1. Summary

| Metric | Before | After |
|---|---|---|
| Root disk used | **56G (89%)** | **23G (37%)** |
| Root free | **7.0G** | **40G** |
| Approx space recovered | — | **~33G** |

Production services remained **active**; GPS workers **RUNNING**; `gps_data` **unchanged**.

---

## 2. Actions Performed

### 2.1 Backup retention (`/var/backups/pistat`)

**Policy:** delete files with `mtime +60` days only; keep last 60 days.

| Item | Value |
|---|---|
| Candidates listed | **107 files** |
| Expected free | **~15.97G** |
| Actually deleted | **107** |
| Bytes freed | **17,148,839,971 (~16.0G)** |
| Remaining | **9 files / 2.2G** (2026-06-07 … 2026-08-02 weekly app archives) |

**Not deleted:** all backups with mtime ≤60 days (listed and retained).

---

### 2.2 systemd journal

| Step | Result |
|---|---|
| Before | **2.1G** |
| `journalctl --vacuum-size=500M` then `--vacuum-time=30d` | Freed **~1.7G** |
| After | **466.0M** |
| Persistent cap | `/etc/systemd/journald.conf.d/pistat-size.conf` → `SystemMaxUse=500M`, `MaxRetentionSec=30day` |
| Service | `systemd-journald` restarted (required for drop-in) |

---

### 2.3 Telescope cleanup

#### Configuration (before → after)

| Setting | Before | After |
|---|---|---|
| `APP_DEBUG` | `true` | `true` *(unchanged)* |
| `TELESCOPE_ENABLED` | `true` | **`false`** |
| `TELESCOPE_RECORD_IN_PRODUCTION` | `true` | **`false`** |
| `config('telescope.enabled')` | true | **false** |
| Providers | `Laravel\Telescope\…` + `App\Providers\TelescopeServiceProvider` | unchanged (collection gated off via env) |
| Scheduled prune | already `telescope:prune --hours=24` daily | unchanged *(no app code edit; ≤30d max)* |

`.env` backup: `.env.bak.diskcleanup-20260805`  
Config cache rebuilt: `php artisan config:cache` (no nginx/php-fpm/mysql restart).

#### Table / file metrics

| Object | Before | After |
|---|---|---|
| `telescope_entries` row count | **113,394** (all within ~2 days; older-than-30d = **0**) | **~113,412** |
| `telescope:prune --hours=720` | — | **0 entries pruned** (none older than 30d) |
| Logical schema size `telescope_entries` | ~72 MB | ~72 MB |
| On-disk `telescope_entries.ibd` | **15G (15,762,194,432 B)** | **~76–80M** |
| On-disk `telescope_entries_tags.ibd` | **180M** | **~3.0M** |
| `OPTIMIZE TABLE telescope_entries` | — | **3.9s** |
| `OPTIMIZE TABLE telescope_entries_tags` | — | **0.5s** |

**Deleted rows:** 0 (data already within 30-day window).  
**Freed disk from Telescope reclaim:** **~14.9G** (tablespace bloat).

**Prevention:** Telescope collection disabled; existing daily `telescope:prune --hours=24` remains scheduled so if re-enabled later, row growth stays bounded. Recommend leaving `TELESCOPE_ENABLED=false` in production.

---

## 3. Space Recovered (breakdown)

| Source | Approx freed |
|---|---:|
| Backups &gt;60 days | **~16.0G** |
| Journal vacuum | **~1.7G** |
| Telescope InnoDB OPTIMIZE | **~14.9G** |
| **Total** | **~32.6G** |

Disk: **89% → 37%** (56G used → 23G used).

---

## 4. Files / Tables Affected

| Path / table | Action |
|---|---|
| `/var/backups/pistat/server-db-*.sql.gz` (and old weeklies) mtime+60 | **Deleted (107)** |
| `/var/backups/pistat/*` mtime≤60d (9 files) | **Kept** |
| `/var/log/journal/…` archived journals | **Vacuumed** |
| `/etc/systemd/journald.conf.d/pistat-size.conf` | **Created** |
| `.env` Telescope flags | **Updated** |
| `telescope_entries` / `telescope_entries_tags` | **OPTIMIZE** (no schema change) |
| `gps_data` / partitions / Redis / uploads | **Not touched** |

---

## 5. Verification — Services & GPS

| Check | Status |
|---|---|
| `nginx` | **active** |
| `php-fpm83` | **active** |
| `mysqld` | **active** |
| `redis-server` | **active** |
| `supervisor` | **active** |
| GPS workers (4/2/2/1) | **RUNNING** |
| `laravel-reverb` | **RUNNING** |
| Queue depths | all **0** |
| `gps_data` logical size | **3694.56 MB** (unchanged) |
| `gps_data` yesterday row count | **5462** (unchanged check) |
| `gps_data#P#p_future.ibd` | **4.5G** (unchanged) |

---

## 6. Top Remaining Consumers (after)

| Path | Size |
|---|---:|
| `/` total | **23G** |
| `/var` | **9.6G** |
| `/usr` | **7.0G** |
| `/home` | **6.5G** |
| MySQL `gps_data` (keep) | ~4.5G file |
| `/var/backups/pistat` | 2.2G |
| Journal | ~466M |

---

## 7. Warnings / Follow-ups

1. **`APP_DEBUG=true` still on** — not changed in this cleanup; recommend separate hardening ticket.  
2. **Backup script hostname mismatch** still exists (`server-*` vs `ubuntu-eco1-…`); fix globs so future retention works without manual cleanup.  
3. **Abandoned uncompressed dumps** remain elsewhere (`/home/api/backups/backup/api_db.sql` ~1.7G, DA `custombuild/mysql_backups/api_db.sql` ~2.2G) — not deleted (outside `/var/backups/pistat` 60-day policy).  
4. **Orphan Redis temp** `/var/lib/redis/temp-2499188.rdb` (~237M) — not deleted in this run.  
5. Telescope UI/data still present locally (~113k recent rows) but **collection disabled**; safe to leave or prune more aggressively later if desired.  
6. No migration/deployment performed.

---

## 8. Rollback Notes

| Change | Rollback |
|---|---|
| `.env` Telescope flags | Restore `.env.bak.diskcleanup-20260805` + `php artisan config:cache` |
| Deleted local backups &gt;60d | Restore from rclone remotes (Arvan/ParsPack) if needed |
| Journal vacuum | **Not reversible** (old OS logs gone) |
| OPTIMIZE Telescope | N/A (data rows preserved; only file bloat removed) |
| journald drop-in | Remove `/etc/systemd/journald.conf.d/pistat-size.conf` + restart journald |

---

*Cleanup execution completed successfully — 2026-08-05*
