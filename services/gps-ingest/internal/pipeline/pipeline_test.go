package pipeline

import (
	"testing"
	"time"

	"github.com/pistat-hamgit/gps-ingest/internal/config"
	"github.com/pistat-hamgit/gps-ingest/internal/metrics"
	"github.com/pistat-hamgit/gps-ingest/internal/validate"
)

func TestEnqueueBackpressureWhenChannelFull(t *testing.T) {
	cfg := config.Config{IngestChannelSize: 1}
	pipe := New(cfg, metrics.NewCollector(), nil, nil, nil, nil, nil)

	if !pipe.Enqueue(sampleBatch()) {
		t.Fatal("expected first enqueue to succeed")
	}
	if pipe.Enqueue(sampleBatch()) {
		t.Fatal("expected second enqueue to fail with backpressure")
	}
}

func TestEnqueueChannelDepthTracking(t *testing.T) {
	cfg := config.Config{IngestChannelSize: 10}
	collector := metrics.NewCollector()
	pipe := New(cfg, collector, nil, nil, nil, nil, nil)

	_ = pipe.Enqueue(sampleBatch())
	if collector.IngestChannelDepth.Load() != 1 {
		t.Fatalf("expected depth 1, got %d", collector.IngestChannelDepth.Load())
	}
}

func sampleBatch() IngestBatch {
	return IngestBatch{Points: []validate.GpsPoint{{
		IMEI:       "863070046120282",
		Coordinate: [2]float64{35.937893, 50.065403},
		DateTime:   "2026-02-25 18:49:45",
		Speed:      0,
		Status:     0,
		Directions: validate.Directions{EW: 3, NS: 1},
	}}}
}

func TestBatchFlushIntervalDefault(t *testing.T) {
	cfg := config.Load()
	if cfg.BatchFlushInterval != 50*time.Millisecond {
		t.Fatalf("expected 50ms default flush interval, got %s", cfg.BatchFlushInterval)
	}
}
