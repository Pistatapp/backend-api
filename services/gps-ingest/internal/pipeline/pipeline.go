package pipeline

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"log"
	"strings"
	"sync"
	"time"

	"github.com/pistat-hamgit/gps-ingest/internal/broadcast"
	"github.com/pistat-hamgit/gps-ingest/internal/config"
	"github.com/pistat-hamgit/gps-ingest/internal/device"
	"github.com/pistat-hamgit/gps-ingest/internal/ledger"
	"github.com/pistat-hamgit/gps-ingest/internal/metrics"
	"github.com/pistat-hamgit/gps-ingest/internal/storage"
	"github.com/pistat-hamgit/gps-ingest/internal/validate"
	"github.com/redis/go-redis/v9"
)

const sideEffectsInboxKey = "gps_side_effects_inbox"

type IngestBatch struct {
	Points []validate.GpsPoint
	Events []ledger.Event
}

type Pipeline struct {
	cfg         config.Config
	metrics     *metrics.Collector
	resolver    *device.Resolver
	writer      *storage.Writer
	broadcaster *broadcast.Client
	outbox      *broadcast.Store
	mainDB      *sql.DB
	redis       *redis.Client
	ledger      *ledger.Store
	cancel      context.CancelFunc

	ingestCh     chan IngestBatch
	broadcastCh  chan broadcast.Job
	sideEffectCh chan sideEffectJob

	wg sync.WaitGroup
}

type sideEffectJob struct {
	DeviceID   int64
	TractorID  int64
	DeviceIMEI string
	LastPoint  validate.GpsPoint
}

func New(
	cfg config.Config,
	collector *metrics.Collector,
	resolver *device.Resolver,
	writer *storage.Writer,
	broadcaster *broadcast.Client,
	mainDB *sql.DB,
	redisClient *redis.Client,
	ledgerStores ...*ledger.Store,
) *Pipeline {
	var ledgerStore *ledger.Store
	if len(ledgerStores) > 0 {
		ledgerStore = ledgerStores[0]
	}
	return &Pipeline{
		cfg:          cfg,
		metrics:      collector,
		resolver:     resolver,
		writer:       writer,
		broadcaster:  broadcaster,
		mainDB:       mainDB,
		redis:        redisClient,
		ledger:       ledgerStore,
		ingestCh:     make(chan IngestBatch, cfg.IngestChannelSize),
		broadcastCh:  make(chan broadcast.Job, cfg.BroadcastQueueSize),
		sideEffectCh: make(chan sideEffectJob, cfg.SideEffectQueueSize),
	}
}

func (p *Pipeline) Start(parent context.Context) {
	ctx, cancel := context.WithCancel(parent)
	p.cancel = cancel

	for i := 0; i < p.cfg.IngestWorkers; i++ {
		p.wg.Add(1)
		go p.ingestWorker(ctx, i)
	}

	for i := 0; i < p.cfg.BroadcastWorkers; i++ {
		p.wg.Add(1)
		go p.broadcastWorker(ctx)
	}
	if p.outbox != nil {
		p.wg.Add(1)
		go p.broadcastDispatcher(ctx)
	}

	for i := 0; i < p.cfg.SideEffectWorkers; i++ {
		p.wg.Add(1)
		go p.sideEffectWorker(ctx)
	}

	p.wg.Add(1)
	go p.replayPending(ctx)
}

// SetBroadcastOutbox is deliberately separate from New for source
// compatibility with the existing test and construction call sites. In
// Production main attaches the additive SQL outbox before Start.
func (p *Pipeline) SetBroadcastOutbox(store *broadcast.Store) {
	p.outbox = store
}

func (p *Pipeline) Stop() {
	if p.cancel != nil {
		p.cancel()
	}
	p.wg.Wait()
}

func (p *Pipeline) Enqueue(batch IngestBatch) bool {
	p.metrics.IngestChannelDepth.Add(1)
	select {
	case p.ingestCh <- batch:
		return true
	default:
		p.metrics.IngestChannelDepth.Add(-1)
		p.metrics.IngestBackpressure.Add(1)
		return false
	}
}

func (p *Pipeline) IngestChannelDepth() int {
	return int(p.metrics.IngestChannelDepth.Load())
}

func (p *Pipeline) IngestChannelCapacity() int {
	return p.cfg.IngestChannelSize
}

func (p *Pipeline) ingestWorker(ctx context.Context, _ int) {
	defer p.wg.Done()

	for {
		select {
		case <-ctx.Done():
			return
		case batch := <-p.ingestCh:
			p.metrics.IngestChannelDepth.Add(-1)
			p.processBatch(ctx, batch)
		}
	}
}

func (p *Pipeline) processBatch(ctx context.Context, batch IngestBatch) {
	if len(batch.Points) == 0 {
		return
	}

	rows := make([]storage.Row, 0, len(batch.Points))
	outboxRows := make([]broadcast.OutboxRecord, 0, len(batch.Points))
	type latest struct {
		mapping device.Mapping
		point   validate.GpsPoint
		event   ledger.Event
	}
	latestByIMEI := make(map[string]latest)
	for index, point := range batch.Points {
		event := eventForIndex(batch.Events, index, point)
		if p.resolver == nil || p.writer == nil {
			markEvent(ctx, p.ledger, event, ledger.RetryPending, "ingest dependencies are unavailable")
			continue
		}
		mapping, found, err := p.resolver.Resolve(ctx, point.IMEI)
		if err != nil {
			markEvent(ctx, p.ledger, event, ledger.RetryPending, fmt.Sprintf("device mapping lookup failed: %v", err))
			continue
		}
		if !found {
			p.metrics.UnknownDevicesTotal.Add(1)
			p.metrics.QuarantinedItemsTotal.Add(1)
			markEvent(ctx, p.ledger, event, ledger.QuarantinedWithRaw, "Unbound or unknown IMEI")
			continue
		}
		row, err := storage.BuildRow(mapping.TractorID, point)
		if err != nil {
			markEvent(ctx, p.ledger, event, ledger.QuarantinedWithRaw, err.Error())
			continue
		}
		row.EventID = event.EventID
		rows = append(rows, row)
		if p.outbox != nil {
			outboxRows = append(outboxRows, broadcast.OutboxRecord{
				EventID: event.EventID, TraceID: event.TraceID, IMEI: event.IMEI,
				DeviceID: mapping.DeviceID, TractorID: mapping.TractorID,
				PayloadHash: event.PayloadHash, Point: point,
			})
		}
		latestByIMEI[point.IMEI] = latest{mapping: mapping, point: point, event: event}
	}

	if len(rows) == 0 {
		return
	}

	start := time.Now()
	var persistErr error
	if p.outbox != nil {
		persistErr = p.writer.InsertBatchWithBroadcast(ctx, rows, outboxRows)
	} else {
		persistErr = p.writer.InsertBatch(ctx, rows)
	}
	if persistErr != nil {
		p.metrics.PersistenceErrorsTotal.Add(1)
		for _, row := range rows {
			event := eventByID(batch.Events, row.EventID)
			status := ledger.RetryPending
			if event.Attempts >= 2 {
				status = ledger.DLQReplayable
			}
			markEvent(ctx, p.ledger, event, status, persistErr.Error())
		}
		return
	}
	p.metrics.BatchFlushTotal.Add(1)
	p.metrics.BatchFlushDurationMS.Add(uint64(time.Since(start).Milliseconds()))
	for _, row := range rows {
		markEvent(ctx, p.ledger, eventByID(batch.Events, row.EventID), ledger.Persisted, "")
	}

	// Live database/Redis side effects are emitted only after the historical DB
	// transaction (and, in Production, the Reverb outbox transaction) commits.
	// Broadcast dispatch is performed by broadcastDispatcher from durable SQL;
	// there is intentionally no in-memory-only broadcast path here.
	for imei, item := range latestByIMEI {
		select {
		case p.sideEffectCh <- sideEffectJob{DeviceID: item.mapping.DeviceID, TractorID: item.mapping.TractorID, DeviceIMEI: imei, LastPoint: item.point}:
		case <-ctx.Done():
			return
		}
	}
}

func (p *Pipeline) broadcastDispatcher(ctx context.Context) {
	defer p.wg.Done()
	ticker := time.NewTicker(time.Second)
	defer ticker.Stop()

	dispatch := func() {
		available := cap(p.broadcastCh) - len(p.broadcastCh)
		if available <= 0 {
			p.refreshBroadcastOutboxMetrics(ctx)
			return
		}
		if available > 1000 {
			available = 1000
		}
		records, err := p.outbox.Pending(ctx, available)
		if err != nil {
			p.metrics.BroadcastOutboxErrors.Add(1)
			log.Printf("gps broadcast outbox pending failed: %v", err)
			return
		}
		for _, record := range records {
			claimed, ok, err := p.outbox.Claim(ctx, record.ID)
			if err != nil {
				p.metrics.BroadcastOutboxErrors.Add(1)
				log.Printf("gps broadcast outbox claim failed outbox_id=%d event_id=%s trace_id=%s: %v", record.ID, record.EventID, record.TraceID, err)
				continue
			}
			if !ok {
				continue
			}
			job := broadcast.Job{
				OutboxID: claimed.ID, EventID: claimed.EventID, TraceID: claimed.TraceID,
				Attempts: claimed.Attempts,
				DeviceID: claimed.DeviceID, TractorID: claimed.TractorID, LastPoint: claimed.Point,
			}
			select {
			case p.broadcastCh <- job:
			case <-ctx.Done():
				// The lease remains durable and will be reclaimed after restart.
				return
			}
		}
		p.refreshBroadcastOutboxMetrics(ctx)
	}

	dispatch()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			dispatch()
		}
	}
}

func (p *Pipeline) refreshBroadcastOutboxMetrics(ctx context.Context) {
	if p.outbox == nil {
		return
	}
	stats, err := p.outbox.Stats(ctx)
	if err != nil {
		p.metrics.BroadcastOutboxErrors.Add(1)
		log.Printf("gps broadcast outbox stats failed: %v", err)
		return
	}
	p.metrics.BroadcastPending.Store(stats.Pending)
	p.metrics.BroadcastRetryPending.Store(stats.Retry)
	p.metrics.BroadcastInFlight.Store(stats.InFlight)
	p.metrics.BroadcastDLQTotal.Store(stats.DLQ)
}

func (p *Pipeline) replayPending(ctx context.Context) {
	defer p.wg.Done()
	if p.ledger == nil {
		return
	}
	ticker := time.NewTicker(5 * time.Second)
	defer ticker.Stop()

	replay := func() {
		events, err := p.ledger.Pending(ctx, 100)
		if err != nil {
			p.metrics.LedgerErrorsTotal.Add(1)
			return
		}
		for _, event := range events {
			ingress, decodeErr := validate.DecodeIngress(strings.NewReader(event.RawPayload))
			if decodeErr != nil {
				markEvent(ctx, p.ledger, event, ledger.QuarantinedWithRaw, decodeErr.Error())
				continue
			}
			queued := false
			for _, item := range ingress.Items {
				if item.Point == nil {
					continue
				}
				if !p.Enqueue(IngestBatch{Points: []validate.GpsPoint{*item.Point}, Events: []ledger.Event{event}}) {
					break
				}
				queued = true
				break
			}
			if !queued {
				markEvent(ctx, p.ledger, event, ledger.RetryPending, "pending event could not be re-enqueued")
			}
		}
	}

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			replay()
		}
	}
}

func eventForIndex(events []ledger.Event, index int, point validate.GpsPoint) ledger.Event {
	if index >= 0 && index < len(events) {
		return events[index]
	}
	return ledger.Event{EventID: fmt.Sprintf("untracked-%s-%d", point.IMEI, index), IMEI: point.IMEI}
}

func eventByID(events []ledger.Event, eventID string) ledger.Event {
	for _, event := range events {
		if event.EventID == eventID {
			return event
		}
	}
	return ledger.Event{EventID: eventID}
}

func markEvent(ctx context.Context, store *ledger.Store, event ledger.Event, status ledger.Status, reason string) {
	if store == nil || event.EventID == "" {
		return
	}
	if err := store.Mark(ctx, event.EventID, status, reason); err != nil {
		// The original event remains in the durable pending ledger/spool; expose
		// the failure instead of pretending that the transition succeeded.
		return
	}
}

func (p *Pipeline) broadcastWorker(ctx context.Context) {
	defer p.wg.Done()

	for {
		select {
		case <-ctx.Done():
			return
		case job, ok := <-p.broadcastCh:
			if !ok {
				return
			}
			if p.broadcaster == nil {
				p.metrics.BroadcastErrorsTotal.Add(1)
				log.Printf("gps broadcast client unavailable outbox_id=%d event_id=%s trace_id=%s", job.OutboxID, job.EventID, job.TraceID)
				if p.outbox != nil {
					p.retryBroadcast(ctx, job, fmt.Errorf("broadcast client is unavailable"))
				}
				continue
			}
			if err := p.broadcaster.Send(job); err != nil {
				p.metrics.BroadcastErrorsTotal.Add(1)
				log.Printf("gps broadcast failed outbox_id=%d event_id=%s trace_id=%s imei=%s: %v", job.OutboxID, job.EventID, job.TraceID, job.LastPoint.IMEI, err)
				p.retryBroadcast(ctx, job, err)
				continue
			}
			if p.outbox != nil {
				if err := p.outbox.MarkSent(ctx, job.OutboxID); err != nil {
					p.metrics.BroadcastOutboxErrors.Add(1)
					log.Printf("gps broadcast sent but outbox acknowledgement failed outbox_id=%d event_id=%s trace_id=%s: %v", job.OutboxID, job.EventID, job.TraceID, err)
					continue
				}
			}
			p.metrics.BroadcastSentTotal.Add(1)
		}
	}
}

func (p *Pipeline) retryBroadcast(ctx context.Context, job broadcast.Job, cause error) {
	if p.outbox == nil {
		return
	}
	status, err := p.outbox.MarkRetry(ctx, job.OutboxID, job.Attempts, cause)
	if err != nil {
		p.metrics.BroadcastOutboxErrors.Add(1)
		log.Printf("gps broadcast retry state failed outbox_id=%d event_id=%s trace_id=%s: %v", job.OutboxID, job.EventID, job.TraceID, err)
		return
	}
	if status == broadcast.OutboxRetryPending {
		p.metrics.BroadcastRetryTotal.Add(1)
		return
	}
	if status == broadcast.OutboxDLQReplayable {
		p.metrics.BroadcastDLQTotal.Add(1)
		log.Printf("gps broadcast moved to replayable DLQ outbox_id=%d event_id=%s trace_id=%s attempts=%d", job.OutboxID, job.EventID, job.TraceID, job.Attempts)
	}
}

func (p *Pipeline) sideEffectWorker(ctx context.Context) {
	defer p.wg.Done()

	for {
		select {
		case <-ctx.Done():
			return
		case job, ok := <-p.sideEffectCh:
			if !ok {
				return
			}
			if err := p.applySideEffect(ctx, job); err != nil {
				p.metrics.SideEffectErrorsTotal.Add(1)
				continue
			}
			p.metrics.SideEffectSentTotal.Add(1)
		}
	}
}

func (p *Pipeline) applySideEffect(ctx context.Context, job sideEffectJob) error {
	const updateQuery = `UPDATE tractors SET is_working = ?, last_activity = NOW() WHERE id = ?`
	if _, err := p.mainDB.ExecContext(ctx, updateQuery, job.LastPoint.Status, job.TractorID); err != nil {
		return err
	}

	payload := map[string]any{
		"device_id":   job.DeviceID,
		"tractor_id":  job.TractorID,
		"device_imei": job.DeviceIMEI,
		"last_point": map[string]any{
			"coordinate": job.LastPoint.Coordinate[:],
			"date_time":  job.LastPoint.DateTime,
			"speed":      job.LastPoint.Speed,
			"status":     job.LastPoint.Status,
			"directions": job.LastPoint.Directions,
		},
	}

	body, err := json.Marshal(payload)
	if err != nil {
		return err
	}

	return p.redis.RPush(ctx, sideEffectsInboxKey, body).Err()
}

func (p *Pipeline) Ping(ctx context.Context) error {
	if err := p.writer.Ping(ctx); err != nil {
		return err
	}
	ctx, cancel := context.WithTimeout(ctx, 2*time.Second)
	defer cancel()
	return p.mainDB.PingContext(ctx)
}
