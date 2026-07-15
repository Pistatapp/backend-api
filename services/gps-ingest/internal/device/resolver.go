package device

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"sync"
	"time"

	"github.com/pistat-hamgit/gps-ingest/internal/metrics"
	"github.com/redis/go-redis/v9"
)

const redisKeyPrefix = "gps:device:"

type Resolver struct {
	redis      *redis.Client
	mainDB     *sql.DB
	l1         *L1Cache
	redisTTL   time.Duration
	metrics    *metrics.Collector
	mu         sync.RWMutex
	localCache map[string]CacheEntry
}

func NewResolver(redisClient *redis.Client, mainDB *sql.DB, l1 *L1Cache, redisTTL time.Duration, collector *metrics.Collector) *Resolver {
	return &Resolver{
		redis:      redisClient,
		mainDB:     mainDB,
		l1:         l1,
		redisTTL:   redisTTL,
		metrics:    collector,
		localCache: make(map[string]CacheEntry),
	}
}

func (r *Resolver) Resolve(ctx context.Context, imei string) (Mapping, bool, error) {
	if mapping, ok := r.getL1(imei); ok {
		r.metrics.IMEICacheL1Hits.Add(1)
		return mapping, true, nil
	}

	key := redisKeyPrefix + imei
	val, err := r.redis.Get(ctx, key).Result()
	if err == nil {
		var mapping Mapping
		if json.Unmarshal([]byte(val), &mapping) == nil && mapping.TractorID > 0 && mapping.DeviceID > 0 {
			r.setL1(imei, mapping)
			r.metrics.IMEICacheL2Hits.Add(1)
			return mapping, true, nil
		}
	} else if err != redis.Nil {
		return Mapping{}, false, err
	}

	r.metrics.IMEICacheMisses.Add(1)
	mapping, found, err := r.loadFromDB(ctx, imei)
	if err != nil {
		return Mapping{}, false, err
	}
	if !found {
		return Mapping{}, false, nil
	}

	payload, _ := json.Marshal(mapping)
	_ = r.redis.Set(ctx, key, payload, r.redisTTL).Err()
	r.setL1(imei, mapping)
	return mapping, true, nil
}

func (r *Resolver) getL1(imei string) (Mapping, bool) {
	now := time.Now()
	r.mu.RLock()
	entry, ok := r.localCache[imei]
	r.mu.RUnlock()
	if !ok || now.After(entry.ExpireAt) {
		return Mapping{}, false
	}
	return entry.Value, true
}

func (r *Resolver) setL1(imei string, mapping Mapping) {
	r.mu.Lock()
	r.localCache[imei] = CacheEntry{
		Value:    mapping,
		ExpireAt: time.Now().Add(r.l1.TTL()),
	}
	r.mu.Unlock()
}

func (r *Resolver) loadFromDB(ctx context.Context, imei string) (Mapping, bool, error) {
	const query = `
		SELECT d.id, d.tractor_id
		FROM gps_devices d
		INNER JOIN tractors t ON t.id = d.tractor_id
		WHERE d.imei = ?
		LIMIT 1
	`

	var deviceID, tractorID int64
	err := r.mainDB.QueryRowContext(ctx, query, imei).Scan(&deviceID, &tractorID)
	if err == sql.ErrNoRows {
		return Mapping{}, false, nil
	}
	if err != nil {
		return Mapping{}, false, fmt.Errorf("lookup device by imei: %w", err)
	}

	return Mapping{TractorID: tractorID, DeviceID: deviceID}, true, nil
}

func CacheKey(imei string) string {
	return redisKeyPrefix + imei
}

func CachePayload(tractorID, deviceID int64) string {
	payload, _ := json.Marshal(Mapping{TractorID: tractorID, DeviceID: deviceID})
	return string(payload)
}
