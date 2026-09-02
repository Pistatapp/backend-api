package storage

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"time"

	"github.com/pistat-hamgit/gps-ingest/internal/broadcast"
	"github.com/pistat-hamgit/gps-ingest/internal/validate"
)

type Row struct {
	TractorID  int64
	Coordinate string
	Speed      int
	Status     int
	Directions string
	IMEI       string
	DateTime   string
	// EventID is carried for observability/idempotency checks. gps_data remains
	// backward-compatible; the event ledger is the durable source of status.
	EventID string
}

type Writer struct {
	db *sql.DB
}

func NewWriter(db *sql.DB) *Writer {
	return &Writer{db: db}
}

func BuildRow(tractorID int64, point validate.GpsPoint) (Row, error) {
	coordinate, err := marshalJSON(point.Coordinate[:])
	if err != nil {
		return Row{}, err
	}
	directions, err := marshalJSON(point.Directions)
	if err != nil {
		return Row{}, err
	}

	return Row{
		TractorID:  tractorID,
		Coordinate: coordinate,
		Speed:      point.Speed,
		Status:     point.Status,
		Directions: directions,
		IMEI:       point.IMEI,
		DateTime:   point.DateTime,
	}, nil
}

func (w *Writer) InsertBatch(ctx context.Context, rows []Row) error {
	return w.InsertBatchWithBroadcast(ctx, rows, nil)
}

// InsertBatchWithBroadcast atomically commits historical GPS rows and their
// post-persistence Reverb outbox records. A failed outbox insert rolls back
// the GPS transaction, leaving the ledger event retryable instead of silently
// losing its live notification.
func (w *Writer) InsertBatchWithBroadcast(ctx context.Context, rows []Row, outboxRows []broadcast.OutboxRecord) error {
	if len(rows) == 0 {
		return nil
	}

	tx, err := w.db.BeginTx(ctx, nil)
	if err != nil {
		return fmt.Errorf("begin tx: %w", err)
	}
	defer tx.Rollback()

	for _, row := range rows {
		// The ledger prevents normal replays from being re-enqueued, while this
		// exact-row guard closes the crash window between gps_data COMMIT and
		// ledger=PERSISTED. Different coordinates at the same device timestamp
		// do not match and are therefore retained.
		var existingID int64
		existingErr := tx.QueryRowContext(ctx, `
			SELECT id FROM gps_data
			WHERE tractor_id = ? AND coordinate = ? AND speed = ? AND status = ?
			  AND directions = ? AND imei = ? AND date_time = ?
			LIMIT 1`, row.TractorID, row.Coordinate, row.Speed, row.Status,
			row.Directions, row.IMEI, row.DateTime).Scan(&existingID)
		if existingErr == nil {
			continue
		}
		if existingErr != sql.ErrNoRows {
			return fmt.Errorf("check duplicate row: %w", existingErr)
		}
		if _, err := tx.ExecContext(ctx, `
			INSERT INTO gps_data
				(tractor_id, coordinate, speed, status, directions, imei, date_time)
			VALUES (?, ?, ?, ?, ?, ?, ?)`, row.TractorID, row.Coordinate, row.Speed,
			row.Status, row.Directions, row.IMEI, row.DateTime); err != nil {
			return fmt.Errorf("insert batch: %w", err)
		}
	}

	for _, outboxRow := range outboxRows {
		if err := broadcast.NewStore(w.db).InsertTx(ctx, tx, outboxRow); err != nil {
			return fmt.Errorf("persist broadcast outbox: %w", err)
		}
	}

	if err := tx.Commit(); err != nil {
		return fmt.Errorf("commit batch: %w", err)
	}

	return nil
}

// InsertIgnoreBatch is retained as a source-compatible name for callers and
// tests, but it deliberately performs a normal INSERT. INSERT IGNORE hid
// constraint/data errors and violated the ingest durability contract.
func (w *Writer) InsertIgnoreBatch(ctx context.Context, rows []Row) error {
	return w.InsertBatch(ctx, rows)
}

func (w *Writer) Ping(ctx context.Context) error {
	ctx, cancel := context.WithTimeout(ctx, 2*time.Second)
	defer cancel()
	return w.db.PingContext(ctx)
}

func marshalJSON(v any) (string, error) {
	b, err := json.Marshal(v)
	if err != nil {
		return "", err
	}
	return string(b), nil
}
