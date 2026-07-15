package metrics

import (
	"fmt"
	"net/http"
	"sync/atomic"
)

type Collector struct {
	IngestRequestsTotal    atomic.Uint64
	IngestAcceptedTotal    atomic.Uint64
	IngestRejectedTotal    atomic.Uint64
	IngestBackpressure     atomic.Uint64
	BatchFlushTotal        atomic.Uint64
	BatchFlushDurationMS   atomic.Uint64
	BroadcastErrorsTotal   atomic.Uint64
	BroadcastSentTotal     atomic.Uint64
	SideEffectErrorsTotal  atomic.Uint64
	SideEffectSentTotal    atomic.Uint64
	IMEICacheL1Hits        atomic.Uint64
	IMEICacheL2Hits        atomic.Uint64
	IMEICacheMisses        atomic.Uint64
	UnknownDevicesTotal    atomic.Uint64
	DroppedRowsTotal       atomic.Uint64
	DroppedBroadcastTotal  atomic.Uint64
	DroppedSideEffectTotal atomic.Uint64
	IngestChannelDepth     atomic.Int64
}

func NewCollector() *Collector {
	return &Collector{}
}

func (c *Collector) Handler() http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/plain; version=0.0.4")
		fmt.Fprintf(w, "# HELP ingest_requests_total Total ingest HTTP requests\n")
		fmt.Fprintf(w, "ingest_requests_total %d\n", c.IngestRequestsTotal.Load())
		fmt.Fprintf(w, "ingest_accepted_total %d\n", c.IngestAcceptedTotal.Load())
		fmt.Fprintf(w, "ingest_rejected_total %d\n", c.IngestRejectedTotal.Load())
		fmt.Fprintf(w, "ingest_backpressure_total %d\n", c.IngestBackpressure.Load())
		fmt.Fprintf(w, "ingest_channel_depth %d\n", c.IngestChannelDepth.Load())
		fmt.Fprintf(w, "batch_flush_total %d\n", c.BatchFlushTotal.Load())
		fmt.Fprintf(w, "batch_flush_duration_ms %d\n", c.BatchFlushDurationMS.Load())
		fmt.Fprintf(w, "broadcast_errors_total %d\n", c.BroadcastErrorsTotal.Load())
		fmt.Fprintf(w, "broadcast_sent_total %d\n", c.BroadcastSentTotal.Load())
		fmt.Fprintf(w, "side_effect_errors_total %d\n", c.SideEffectErrorsTotal.Load())
		fmt.Fprintf(w, "side_effect_sent_total %d\n", c.SideEffectSentTotal.Load())
		fmt.Fprintf(w, "imei_cache_l1_hits %d\n", c.IMEICacheL1Hits.Load())
		fmt.Fprintf(w, "imei_cache_l2_hits %d\n", c.IMEICacheL2Hits.Load())
		fmt.Fprintf(w, "imei_cache_misses %d\n", c.IMEICacheMisses.Load())
		fmt.Fprintf(w, "unknown_devices_total %d\n", c.UnknownDevicesTotal.Load())
		fmt.Fprintf(w, "dropped_rows_total %d\n", c.DroppedRowsTotal.Load())
		fmt.Fprintf(w, "dropped_broadcast_total %d\n", c.DroppedBroadcastTotal.Load())
		fmt.Fprintf(w, "dropped_side_effect_total %d\n", c.DroppedSideEffectTotal.Load())
	}
}
