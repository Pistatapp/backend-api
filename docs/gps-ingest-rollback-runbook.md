# GPS Ingest Go Service — Rollback Runbook

## Instant rollback to Laravel (no code deploy)

1. **Disable Go routing in nginx**
   - Comment out the `location = /api/gps/reports` block from `services/gps-ingest/deploy/nginx-gps.conf`
   - Reload nginx: `sudo nginx -t && sudo systemctl reload nginx`

2. **Re-enable Laravel GPS queue workers**
   - Set `autostart=true` for `gps-processing` and `gps-broadcast` in supervisor
   - `sudo supervisorctl reread && sudo supervisorctl update`
   - `sudo supervisorctl start gps-processing:* gps-broadcast:*`

3. **Optional: stop Go service**
   - `sudo systemctl stop gps-ingest`

4. **Verify fallback**
   - `GPS_INGEST_DRIVER=laravel` in `.env` (default)
   - POST a test payload to `/api/gps/reports`
   - Confirm `IngestGpsData` is queued and data appears in `gps_data`

## Roll forward to Go again

1. Build binary: `cd services/gps-ingest && make build`
2. Start service: `sudo systemctl start gps-ingest`
3. Enable nginx Go location block and reload nginx
4. Set `autostart=false` on `gps-processing` and `gps-broadcast` supervisor programs

## Monitoring checklist

- Go health: `GET http://127.0.0.1:8081/healthz`
- Go metrics: `GET http://127.0.0.1:8081/metrics`
- Watch `ingest_channel_depth`, `broadcast_errors_total`, `dropped_broadcast_total`, `dropped_rows_total`
- Watch Redis list length: `LLEN gps_side_effects_inbox`
- Ensure `gps:consume-side-effects` supervisor program is running
- See also: `docs/gps-ingest-monitoring.md` and `docs/gps-ingest-shadow-validation.md`

## Load test notes

- Handler benchmark: `cd services/gps-ingest && make bench`
- Target: HTTP handler sustains high throughput with I/O off hot path
- Tune via env: `INGEST_WORKERS`, `BATCH_FLUSH_INTERVAL_MS`, `BATCH_FLUSH_SIZE`, `DB_GPS_POOL_MAX`
