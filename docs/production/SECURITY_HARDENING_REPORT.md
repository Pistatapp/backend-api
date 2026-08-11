# PiStat Production — Security Hardening Report

**Host:** `ubuntu-eco1-small1-bamdad-1`  
**Audit date:** 2026-08-05 (post disk cleanup)  
**Mode:** READ-ONLY — **no changes applied**  
**Context:** Follow-up to `DISK_CLEANUP_EXECUTION_REPORT.md`

---

## Executive Summary

Disk cleanup improved capacity (**37% used**) and disabled Telescope collection, but **several critical security gaps remain**. Highest priorities: world-readable `.env`, `APP_DEBUG=true`, SSH password + root login, and MySQL listening on all interfaces (partially mitigated by CSF).

| Area | Status | Severity |
|---|---|---|
| Telescope collection | **Disabled** (`TELESCOPE_ENABLED=false`) | OK (residual UI/data risk Low) |
| `APP_DEBUG` | **Still `true`** | **Critical** |
| `.env` permissions | **`755` / world-readable** | **Critical** |
| SSH | Password auth + root login enabled | **Critical** |
| MySQL bind | `0.0.0.0:3306` (CSF omits 3306 in `TCP_IN`) | **High** (mitigated, not fixed) |
| Firewall | CSF/LFD active; UFW inactive | Medium / OK with caveats |
| Backup retention scripts | Hostname glob mismatch still present | **High** (ops/security of recovery) |
| Log rotation | Partial gaps (`gps_lag`, Laravel `laravel.log`) | Medium |

---

## 1. APP_DEBUG

| Check | Value |
|---|---|
| `.env` `APP_ENV` | `production` |
| `.env` `APP_DEBUG` | **`true`** |
| `config('app.debug')` | **`true`** |

**Risk:** Stack traces, env fragments, and SQL/path details can leak to HTTP clients on errors.

**Recommended fix (not applied):**
1. Set `APP_DEBUG=false` in `.env`
2. `php artisan config:cache`
3. Verify a deliberate 500 does not expose trace (staging first if possible)

---

## 2. Telescope Status

| Check | Value |
|---|---|
| `TELESCOPE_ENABLED` | **`false`** |
| `TELESCOPE_RECORD_IN_PRODUCTION` | **`false`** |
| Runtime `config('telescope.enabled')` | **`false`** |
| Service providers still registered | Yes (gated by `enabled`) |
| Scheduled prune | `telescope:prune --hours=24` daily (already present) |
| On-disk table | Reclaimed (~80M `.ibd`); ~113k recent rows remain |

**Risk:** Low while disabled. Residual: Telescope routes/UI may still exist if authorized; historical debug rows retain request metadata.

**Recommended fix (not applied):**
- Keep disabled in production permanently unless explicitly needed
- Optionally restrict Telescope routes to admin IPs / remove from prod providers in a future code change
- Do **not** re-enable without disk + auth controls

---

## 3. `.env` Permissions

| Path | Mode | Owner |
|---|---|---|
| `/home/api/domains/api.pistatapp.ir/public_html/.env` | **`755` (`rwxr-xr-x`)** | `ubuntu:ubuntu` |
| Same via symlink `/home/api/public_html/.env` | **`755`** | `ubuntu:ubuntu` |
| `.env.bak.diskcleanup-20260805` | **`755`** | `ubuntu:ubuntu` |

**Risk:** World-readable secrets (DB password, `APP_KEY`, Reverb, SMS, payment, cloud keys). Backup `.env` copy has the same exposure.

**Recommended fix (not applied):**
```bash
# Example only — do not run in this audit
chmod 640 .env .env.bak.diskcleanup-20260805
chown api:api .env   # or api:www-data / DA access group as appropriate
```
Then rotate any secrets that may have been exposed historically.

Also tighten `storage/` and `bootstrap/cache/` currently **`777`**.

---

## 4. SSH Configuration

**Effective settings (`sshd -T`):**

| Setting | Effective |
|---|---|
| `permitrootlogin` | **yes** |
| `passwordauthentication` | **yes** |
| `pubkeyauthentication` | yes |
| `kbdinteractiveauthentication` | **yes** |
| Listen | `0.0.0.0:22` + `[::]:22` |

**Config conflict note:** Drop-ins disagree (`60-cloudimg-settings.conf` sets `PasswordAuthentication no`; `99-allow-password.conf` re-enables **yes**). Effective result: **password auth on**.

**Risk:** Brute-force / credential stuffing; root password login expands blast radius.

**Recommended fix (not applied):**
1. Install admin SSH keys; verify key login works
2. Set `PasswordAuthentication no`, `KbdInteractiveAuthentication no`
3. Set `PermitRootLogin no` (or `prohibit-password`)
4. Remove/override `99-allow-password.conf`
5. Optionally restrict CSF SSH to admin IPs; keep fail2ban/LFD

---

## 5. MySQL Exposure

| Check | Value |
|---|---|
| Listen | **`0.0.0.0:3306`** and **`[::]:3306`** |
| `bind_address` | empty (all interfaces) |
| `skip_networking` | OFF |
| App connection | `127.0.0.1` (good) |
| CSF `TCP_IN` | **Does not include 3306** |

**Risk:** Database process is network-bound on all interfaces. CSF currently blocks inbound 3306 from the internet (**mitigation**), but:
- Misconfiguration / CSF stop / IPv6 path mistakes can expose MariaDB
- Any host-local compromise can still reach DB widely

**Recommended fix (not applied):**
1. Set MariaDB `bind-address = 127.0.0.1` (and disable IPv6 listen if unused)
2. Restart MariaDB in maintenance window
3. Confirm `ss -tlnp | grep 3306` shows localhost only
4. Keep 3306 out of CSF `TCP_IN`

---

## 6. Firewall

| Control | Status |
|---|---|
| UFW | **inactive** |
| CSF | **active** |
| LFD | **active** |
| CSF INPUT policy | DROP (from `csf -l` sample) |

**CSF `TCP_IN` (allow):**  
`20,21,22,25,53,853,80,110,143,443,465,587,993,995,2222` + passive FTP range `35000:35999`

**Notable:**
- **3306 / 8088 not in `TCP_IN`** → external MySQL/Reverb direct access likely blocked
- **22 open to world** with password auth → high risk
- Mail + FTP ports open (expected for DA mail/hosting role)
- Reverb binds `0.0.0.0:8088`; clients should use `ws.pistatapp.ir:443` via nginx

**Recommended fix (not applied):**
- Prefer CSF over enabling UFW (avoid dual firewall conflict)
- Restrict SSH source IPs in CSF where possible
- Confirm 8088 remains denied inbound; terminate WSS only via nginx
- Review whether FTP (20/21) must stay public

---

## 7. Backup Retention

| Item | Current state |
|---|---|
| Local dir | `/var/backups/pistat` — **9 files / 2.2G** (post cleanup) |
| Remote | rclone → Arvan + ParsPack (scripts) |
| DB script local retention | `${HOST}-db-*-*.sql.gz` **mtime +14** delete |
| Weekly script local retention | `${HOST}-app-weekly-*` / `${HOST}-db-*-weekly-*` **mtime +30** |
| Hostname | `ubuntu-eco1-small1-bamdad-1` |
| Legacy `server-*` files still present | **2** weeklies (Jun 7, Jun 14) — within 60d keep window |

**Risk:** Retention `find` patterns only match `${hostname}-*`. Historical failure filled disk; **bug still in scripts** — any future rename/legacy files won’t age out. Abandoned uncompressed dumps still on disk:
- `/home/api/backups/backup/api_db.sql` (~1.7G, world-readable `api:api` `644`)
- `/usr/local/directadmin/custombuild/mysql_backups/api_db.sql` (~2.2G)

Uncompressed SQL with possible sensitive data at `644` is a **data exposure** risk.

**Recommended fix (not applied):**
1. Fix retention globs to include `server-*` **or** standardize filenames
2. Align policy (e.g. local 14/30/60 days) and document remote as system of record
3. Offload/remove abandoned uncompressed dumps after remote verify; never leave `*.sql` mode `644`
4. Restrict backup directory permissions (`750`/`640`)

---

## 8. Log Rotation

| Log source | Rotation status | Risk |
|---|---|---|
| rsyslog (`syslog`, `auth.log`, …) | Weekly, rotate 4, compress | OK |
| nginx | logrotate present (DA-style) | OK |
| journald | Cap **500M / 30d** (drop-in from cleanup) | OK |
| Supervisor GPS worker logs | `stdout_logfile_maxbytes=50MB`, backups=5 | OK |
| `storage/logs/laravel.log` | **No logrotate**; 12M, mode `666` | Medium |
| `/var/log/gps_lag.log` | **No logrotate**; **57M**, grows every minute | Medium |
| `/var/log/supervisor/supervisord.log` | ~33M previously; check rotate | Low–Med |
| Redis / DA | package logrotate present | OK |

**Recommended fix (not applied):**
1. Add logrotate for `/var/log/gps_lag.log` (daily, size-based, compress)
2. Fix or disable broken `gps_lag_check.sh` (queries obsolete `gps_reports`) — **needs monitoring approval**
3. Add logrotate for Laravel `storage/logs/*.log` or enable daily Laravel log channel
4. Ensure `laravel.log` is not world-writable (`666` → `640`)

---

## 9. Additional Findings (related)

| Finding | Severity | Notes |
|---|---|---|
| `storage/` + `bootstrap/cache/` mode `777` | Medium | World-writable app dirs |
| Reverb `0.0.0.0:8088` | Medium | Relying on CSF deny; prefer `127.0.0.1` + nginx |
| Port `27159` listening on `0.0.0.0` | Low–Med | Identify service; close if unused |
| Apache `*:8080/*:8081` | Low | DA stack; confirm not public beyond need |
| Redis localhost-only | OK | `127.0.0.1:6379` |

---

## 10. Recommended Fix Priority (do not execute in this audit)

### P0 — Immediate (critical)

| # | Fix | Impact |
|---|---|---|
| 1 | `chmod 640` + correct owner on `.env` and `.env.bak*` | Stop secret world-read |
| 2 | `APP_DEBUG=false` + `config:cache` | Stop error leakage |
| 3 | SSH: key-only, disable root password login | Stop brute-force root risk |

### P1 — Short term

| # | Fix | Impact |
|---|---|---|
| 4 | MariaDB `bind-address=127.0.0.1` | Defense in depth |
| 5 | Fix backup retention hostname globs | Prevent disk/security recurrence |
| 6 | Secure/remove abandoned `api_db.sql` dumps | Reduce data leak surface |
| 7 | Reverb bind localhost + confirm CSF | Reduce WS attack surface |
| 8 | Tighten `storage`/`bootstrap/cache` to `775`/`api` | Limit local write abuse |

### P2 — Hygiene

| # | Fix | Impact |
|---|---|---|
| 9 | logrotate for `gps_lag.log` + Laravel logs | Capacity + forensics hygiene |
| 10 | Identify/close port `27159` if unused | Shrink exposure |
| 11 | CSF restrict SSH by IP if operationally feasible | Reduce auth noise |
| 12 | Rotate secrets if `.env` was ever world-readable on a shared host | Assume compromise window |

---

## 11. What Improved Since Cleanup

| Item | Before cleanup | Now |
|---|---|---|
| Telescope recording | Enabled + 15G bloat | **Disabled**; tablespace reclaimed |
| Disk pressure | 89% (outage risk) | **37%** |
| Journal unbounded | ~2.1G | **Capped 500M / 30d** |
| Local backup pile | 19G stale | **2.2G / 60d policy applied once** |

Remaining security debt is mostly **access control and secret hygiene**, not capacity.

---

## 12. Audit Completeness

| Requested area | Audited | Changed |
|---|---|---|
| APP_DEBUG | Yes | **No** |
| Telescope status | Yes | **No** (already disabled earlier) |
| `.env` permissions | Yes | **No** |
| SSH configuration | Yes | **No** |
| MySQL exposure | Yes | **No** |
| Firewall | Yes | **No** |
| Backup retention | Yes | **No** |
| Log rotation | Yes | **No** |

---

*Security hardening report — read-only, 2026-08-05. No fixes applied.*
