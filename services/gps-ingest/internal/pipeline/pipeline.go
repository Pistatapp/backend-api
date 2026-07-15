package pipeline

import (
	"context"
	"database/sql"
	"encoding/json"
	"sync"
	"time"

	"github.com/pistat-hamgit/gps-ingest/internal/broadcast"
	"github.com/pistat-hamgit/gps-ingest/internal/config"
	"github.com/pistat-hamgit/gps-ingest/internal/device"
	"github.com/pistat-hamgit/gps-ingest/internal/metrics"
	"github.com/pistat-hamgit/gps-ingest/internal/storage"
	"github.com/pistat-hamgit/gps-ingest/internal/validate"
	"github.com/redis/go-redis/v9"
)

const sideEffectsInboxKey = "gps_side_effects_inbox"

type IngestBatch struct {
	Points []validate.GpsPoint
}

type Pipeline struct {
	cfg         config.Config
	metrics     *metrics.Collector
	resolver    *device.Resolver
	writer      *storage.Writer
	broadcaster *broadcast.Client
	mainDB      *sql.DB
	redis       *redis.Client
	cancel      context.CancelFunc

	ingestCh     chan IngestBatch
	broadcastCh  chan broadcast.Job
	sideEffectCh chan sideEffectJob
	rowCh        chan storage.Row

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
) *Pipeline {
	return &Pipeline{
		cfg:          cfg,
		metrics:      collector,
		resolver:     resolver,
		writer:       writer,
		broadcaster:  broadcaster,
		mainDB:       mainDB,
		redis:        redisClient,
		ingestCh:     make(chan IngestBatch, cfg.IngestChannelSize),
		broadcastCh:  make(chan broadcast.Job, cfg.BroadcastQueueSize),
		sideEffectCh: make(chan sideEffectJob, cfg.SideEffectQueueSize),
		rowCh:        make(chan storage.Row, cfg.BatchFlushSize*2),
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

	for i := 0; i < p.cfg.SideEffectWorkers; i++ {
		p.wg.Add(1)
		go p.sideEffectWorker(ctx)
	}

	p.wg.Add(1)
	go p.batchFlusher(ctx)
}

func (p *Pipeline) Stop() {
	if p.cancel != nil {
		p.cancel()
	}
	close(p.ingestCh)
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

	for batch := range p.ingestCh {
		p.metrics.IngestChannelDepth.Add(-1)
		p.processBatch(ctx, batch)
	}
}

func (p *Pipeline) processBatch(ctx context.Context, batch IngestBatch) {
	if len(batch.Points) == 0 {
		return
	}

	imei := batch.Points[0].IMEI
	mapping, found, err := p.resolver.Resolve(ctx, imei)
	if err != nil || !found {
		if err == nil {
			p.metrics.UnknownDevicesTotal.Add(1)
		}
		return
	}

	for _, point := range batch.Points {
		row, err := storage.BuildRow(mapping.TractorID, point)
		if err != nil {
			continue
		}
		select {
		case p.rowCh <- row:
		default:
			p.metrics.DroppedRowsTotal.Add(1)
		}
	}

	lastPoint := batch.Points[len(batch.Points)-1]

	select {
	case p.broadcastCh <- broadcast.Job{
		DeviceID:  mapping.DeviceID,
		TractorID: mapping.TractorID,
		LastPoint: lastPoint,
	}:
	default:
		p.metrics.DroppedBroadcastTotal.Add(1)
	}

	select {
	case p.sideEffectCh <- sideEffectJob{
		DeviceID:   mapping.DeviceID,
		TractorID:  mapping.TractorID,
		DeviceIMEI: imei,
		LastPoint:  lastPoint,
	}:
	default:
		p.metrics.DroppedSideEffectTotal.Add(1)
	}
}

func (p *Pipeline) batchFlusher(ctx context.Context) {
	defer p.wg.Done()

	ticker := time.NewTicker(p.cfg.BatchFlushInterval)
	defer ticker.Stop()

	buffer := make([]storage.Row, 0, p.cfg.BatchFlushSize)
	flush := func() {
		if len(buffer) == 0 {
			return
		}
		start := time.Now()
		if err := p.writer.InsertIgnoreBatch(ctx, buffer); err == nil {
			p.metrics.BatchFlushTotal.Add(1)
			p.metrics.BatchFlushDurationMS.Add(uint64(time.Since(start).Milliseconds()))
		}
		buffer = buffer[:0]
	}

	for {
		select {
		case <-ctx.Done():
			flush()
			return
		case row, ok := <-p.rowCh:
			if !ok {
				flush()
				return
			}
			buffer = append(buffer, row)
			if len(buffer) >= p.cfg.BatchFlushSize {
				flush()
			}
		case <-ticker.C:
			flush()
		}
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
			if err := p.broadcaster.Send(job); err != nil {
				p.metrics.BroadcastErrorsTotal.Add(1)
				continue
			}
			p.metrics.BroadcastSentTotal.Add(1)
		}
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
