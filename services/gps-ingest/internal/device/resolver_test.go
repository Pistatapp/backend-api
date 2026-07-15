package device

import (
	"context"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	"github.com/pistat-hamgit/gps-ingest/internal/metrics"
	"github.com/redis/go-redis/v9"
)

func TestResolverReadsLaravelWrittenRedisKey(t *testing.T) {
	mr, err := miniredis.Run()
	if err != nil {
		t.Fatalf("start miniredis: %v", err)
	}
	defer mr.Close()

	redisClient := redis.NewClient(&redis.Options{Addr: mr.Addr()})
	defer redisClient.Close()

	mr.Set("gps:device:863070046120282", `{"tractor_id":42,"device_id":7}`)

	resolver := NewResolver(
		redisClient,
		nil,
		NewL1Cache(5*time.Minute),
		time.Hour,
		metrics.NewCollector(),
	)

	mapping, found, err := resolver.Resolve(context.Background(), "863070046120282")
	if err != nil {
		t.Fatalf("resolve failed: %v", err)
	}
	if !found {
		t.Fatal("expected device mapping to be found")
	}
	if mapping.TractorID != 42 || mapping.DeviceID != 7 {
		t.Fatalf("unexpected mapping: %+v", mapping)
	}
}

func TestResolverUsesL1BeforeRedis(t *testing.T) {
	mr, err := miniredis.Run()
	if err != nil {
		t.Fatalf("start miniredis: %v", err)
	}
	defer mr.Close()

	redisClient := redis.NewClient(&redis.Options{Addr: mr.Addr()})
	defer redisClient.Close()

	collector := metrics.NewCollector()
	resolver := NewResolver(
		redisClient,
		nil,
		NewL1Cache(5*time.Minute),
		time.Hour,
		collector,
	)

	resolver.setL1("863070046120282", Mapping{TractorID: 10, DeviceID: 20})

	mapping, found, err := resolver.Resolve(context.Background(), "863070046120282")
	if err != nil {
		t.Fatalf("resolve failed: %v", err)
	}
	if !found {
		t.Fatal("expected mapping from L1")
	}
	if mapping.TractorID != 10 || mapping.DeviceID != 20 {
		t.Fatalf("unexpected mapping: %+v", mapping)
	}
	if collector.IMEICacheL1Hits.Load() != 1 {
		t.Fatalf("expected L1 hit metric, got %d", collector.IMEICacheL1Hits.Load())
	}
	if collector.IMEICacheL2Hits.Load() != 0 {
		t.Fatalf("expected no L2 hit, got %d", collector.IMEICacheL2Hits.Load())
	}
}
