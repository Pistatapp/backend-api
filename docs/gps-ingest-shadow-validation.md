# GPS Ingest Shadow Mode Validation (Phase 5)

Run this checklist during the 7-day shadow window before production cutover.

## Prerequisites

- Go binary built and running: `systemctl status gps-ingest`
- Nginx using `services/gps-ingest/deploy/nginx-gps-shadow.conf`
- Laravel `gps-processing`, `gps-broadcast`, and `gps-side-effects` workers still running
- `php artisan gps:consume-side-effects` supervisor program running

## Daily checks

1. **Row count parity**
   - Compare `gps_data` inserts from Go vs Laravel mirror over 24h
   - Both use `INSERT IGNORE` — counts should match unless one path errors

2. **Go health**
   - `curl -sf http://127.0.0.1:8081/healthz`
   - `curl -sf http://127.0.0.1:8081/metrics | grep -E 'ingest_|broadcast_|dropped_'`

3. **Broadcast verification**
   - Confirm `report-received` and `tractor.status.changed` events arrive in staging UI
   - Compare payload `date_time` Jalali format against Laravel output

4. **Side effects**
   - `redis-cli LLEN gps_side_effects_inbox` should stay near zero
   - Task zone status updates still occur for tractors inside active tasks

5. **Error logs**
   - `journalctl -u gps-ingest --since "24 hours ago" | grep -i error`
   - Laravel `storage/logs/gps-*.log`

## Exit criteria (proceed to Phase 6)

- 7 consecutive days with zero unexplained row count divergence
- No sustained growth in `ingest_backpressure_total` or `dropped_broadcast_total`
- Broadcast payloads verified in browser
- Side-effect consumer healthy

## Cutover action

1. Switch nginx to `nginx-gps-production.conf`
2. Apply `deploy/supervisor/gps-go-cutover.conf` (`autostart=false` on PHP ingest/broadcast workers)
3. `supervisorctl reread && supervisorctl update`
