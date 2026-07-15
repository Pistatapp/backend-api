# GPS Ingest Monitoring and Alerting (Phase 7)

## Endpoints

| Endpoint | Purpose |
|----------|---------|
| `GET /healthz` | Liveness — DB reachable, channel depth < 80% |
| `GET /metrics` | Prometheus-style counters |

## Recommended alerts

| Metric | Threshold | Action |
|--------|-----------|--------|
| `ingest_channel_depth` | > 80% of `INGEST_CHANNEL_SIZE` | Scale workers or investigate DB slowdown |
| `ingest_backpressure_total` | Sustained increase | Channel saturated — tune pool sizes |
| `broadcast_errors_total` | > 0 sustained | Check Reverb connectivity and credentials |
| `dropped_broadcast_total` | Sustained increase | Increase `BROADCAST_WORKERS` or `BROADCAST_QUEUE_SIZE` |
| `dropped_rows_total` | > 0 sustained | Increase batch flusher throughput or `rowCh` buffer |
| `gps_side_effects_inbox` Redis LLEN | > 100 | Ensure `gps:consume-side-effects` is running |
| `/healthz` | non-200 | Restart `gps-ingest` service |

## Useful commands

```bash
curl -s http://127.0.0.1:8081/metrics
redis-cli LLEN gps_side_effects_inbox
journalctl -u gps-ingest -f
supervisorctl status gps-side-effects-consumer:*
```

## Laravel fallback verification

```bash
# In .env
GPS_INGEST_DRIVER=laravel

php artisan test --filter=GpsReportsEndpointTest
php artisan test --filter=IngestGpsDataTest
```

Restore `GPS_INGEST_DRIVER=go` or route via nginx after verification.
