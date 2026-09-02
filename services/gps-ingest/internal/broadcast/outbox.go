package broadcast

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"time"

	"github.com/pistat-hamgit/gps-ingest/internal/validate"
)

const (
	OutboxPending       = "PENDING"
	OutboxInFlight      = "IN_FLIGHT"
	OutboxRetryPending  = "RETRY_PENDING"
	OutboxSent          = "SENT"
	OutboxDLQReplayable = "DLQ_REPLAYABLE"

	outboxLease       = 5 * time.Minute
	outboxMaxAttempts = 12
)

// OutboxRecord is the durable unit of a post-persistence Reverb publish. The
// point is kept as JSON so retry/reclaim never has to reconstruct it from the
// mutable latest-state cache. It does not alter the Android payload.
type OutboxRecord struct {
	ID          int64
	EventID     string
	TraceID     string
	IMEI        string
	DeviceID    int64
	TractorID   int64
	PayloadHash string
	Point       validate.GpsPoint
	Status      string
	Attempts    int
	LastError   string
}

type OutboxStats struct {
	Pending  uint64
	InFlight uint64
	Retry    uint64
	Sent     uint64
	DLQ      uint64
}

type Store struct {
	db *sql.DB
}

func NewStore(db *sql.DB) *Store {
	return &Store{db: db}
}

// InsertTx must be called with the same transaction that writes gps_data. A
// duplicate event with the same hash is idempotent; an event-id collision with
// a different payload is an explicit error and rolls back the GPS transaction.
func (s *Store) InsertTx(ctx context.Context, tx *sql.Tx, record OutboxRecord) error {
	if s == nil || tx == nil {
		return fmt.Errorf("broadcast outbox transaction is unavailable")
	}
	if record.EventID == "" {
		return fmt.Errorf("broadcast outbox event_id is empty")
	}

	var existingHash sql.NullString
	err := tx.QueryRowContext(ctx,
		`SELECT payload_hash FROM gps_broadcast_outbox WHERE event_id = ? LIMIT 1`,
		record.EventID).Scan(&existingHash)
	if err == nil {
		if existingHash.Valid && record.PayloadHash != "" && existingHash.String != record.PayloadHash {
			return fmt.Errorf("broadcast outbox event_id collision: %s", record.EventID)
		}
		return nil
	}
	if err != sql.ErrNoRows {
		return fmt.Errorf("check broadcast outbox duplicate: %w", err)
	}

	payload, err := json.Marshal(record.Point)
	if err != nil {
		return fmt.Errorf("marshal broadcast outbox point: %w", err)
	}
	if _, err := tx.ExecContext(ctx, `
		INSERT INTO gps_broadcast_outbox
			(event_id, trace_id, imei, device_id, tractor_id, payload_hash,
			 point_payload, status, attempts, next_attempt_at, locked_until,
			 last_error, sent_at, created_at, updated_at)
		VALUES (?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, NULLIF(?, ''), ?, ?, 0,
			NULL, NULL, NULL, NULL, NOW(), NOW())`,
		record.EventID, record.TraceID, record.IMEI, record.DeviceID,
		record.TractorID, record.PayloadHash, string(payload), OutboxPending); err != nil {
		return fmt.Errorf("insert broadcast outbox: %w", err)
	}
	return nil
}

func (s *Store) Pending(ctx context.Context, limit int) ([]OutboxRecord, error) {
	if s == nil || s.db == nil {
		return nil, fmt.Errorf("broadcast outbox database is unavailable")
	}
	if limit <= 0 || limit > 5000 {
		limit = 5000
	}

	rows, err := s.db.QueryContext(ctx, `
		SELECT id, event_id, COALESCE(trace_id, ''), COALESCE(imei, ''),
		       device_id, tractor_id, COALESCE(payload_hash, ''), point_payload,
		       status, attempts, COALESCE(last_error, '')
		FROM gps_broadcast_outbox
		WHERE (status IN (?, ?) AND (next_attempt_at IS NULL OR next_attempt_at <= NOW()))
		   OR (status = ? AND (locked_until IS NULL OR locked_until <= NOW()))
		ORDER BY id ASC
		LIMIT ?`, OutboxPending, OutboxRetryPending, OutboxInFlight, limit)
	if err != nil {
		return nil, fmt.Errorf("select pending broadcast outbox: %w", err)
	}
	defer rows.Close()

	result := make([]OutboxRecord, 0)
	for rows.Next() {
		var record OutboxRecord
		var payload []byte
		if err := rows.Scan(&record.ID, &record.EventID, &record.TraceID,
			&record.IMEI, &record.DeviceID, &record.TractorID, &record.PayloadHash,
			&payload, &record.Status, &record.Attempts, &record.LastError); err != nil {
			return nil, fmt.Errorf("scan pending broadcast outbox: %w", err)
		}
		if err := json.Unmarshal(payload, &record.Point); err != nil {
			return nil, fmt.Errorf("decode broadcast outbox %d: %w", record.ID, err)
		}
		result = append(result, record)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("iterate pending broadcast outbox: %w", err)
	}
	return result, nil
}

// Claim is atomic and lease-based. An IN_FLIGHT row is never deleted; after a
// process crash or worker restart it becomes eligible for reclaim.
func (s *Store) Claim(ctx context.Context, id int64) (OutboxRecord, bool, error) {
	if s == nil || s.db == nil {
		return OutboxRecord{}, false, fmt.Errorf("broadcast outbox database is unavailable")
	}
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return OutboxRecord{}, false, fmt.Errorf("begin broadcast outbox claim: %w", err)
	}
	defer tx.Rollback()

	var record OutboxRecord
	var payload []byte
	err = tx.QueryRowContext(ctx, `
		SELECT id, event_id, COALESCE(trace_id, ''), COALESCE(imei, ''),
		       device_id, tractor_id, COALESCE(payload_hash, ''), point_payload,
		       status, attempts, COALESCE(last_error, '')
		FROM gps_broadcast_outbox
		WHERE id = ?
		  AND ((status IN (?, ?) AND (next_attempt_at IS NULL OR next_attempt_at <= NOW()))
		    OR (status = ? AND (locked_until IS NULL OR locked_until <= NOW())))
		FOR UPDATE`, id, OutboxPending, OutboxRetryPending, OutboxInFlight).Scan(
		&record.ID, &record.EventID, &record.TraceID, &record.IMEI, &record.DeviceID,
		&record.TractorID, &record.PayloadHash, &payload, &record.Status,
		&record.Attempts, &record.LastError)
	if err == sql.ErrNoRows {
		return OutboxRecord{}, false, nil
	}
	if err != nil {
		return OutboxRecord{}, false, fmt.Errorf("select broadcast outbox claim: %w", err)
	}
	if err := json.Unmarshal(payload, &record.Point); err != nil {
		return OutboxRecord{}, false, fmt.Errorf("decode broadcast outbox claim %d: %w", id, err)
	}

	nextAttempts := record.Attempts + 1
	if _, err := tx.ExecContext(ctx, `
		UPDATE gps_broadcast_outbox
		SET status = ?, attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL 5 MINUTE),
		    updated_at = NOW()
		WHERE id = ?`, OutboxInFlight, nextAttempts, id); err != nil {
		return OutboxRecord{}, false, fmt.Errorf("lock broadcast outbox %d: %w", id, err)
	}
	if err := tx.Commit(); err != nil {
		return OutboxRecord{}, false, fmt.Errorf("commit broadcast outbox claim %d: %w", id, err)
	}
	record.Status = OutboxInFlight
	record.Attempts = nextAttempts
	return record, true, nil
}

func (s *Store) MarkSent(ctx context.Context, id int64) error {
	if s == nil || s.db == nil {
		return fmt.Errorf("broadcast outbox database is unavailable")
	}
	result, err := s.db.ExecContext(ctx, `
		UPDATE gps_broadcast_outbox
		SET status = ?, sent_at = COALESCE(sent_at, NOW()), locked_until = NULL,
		    next_attempt_at = NULL, last_error = NULL, updated_at = NOW()
		WHERE id = ? AND status = ?`, OutboxSent, id, OutboxInFlight)
	if err != nil {
		return fmt.Errorf("mark broadcast outbox %d sent: %w", id, err)
	}
	if affected, err := result.RowsAffected(); err == nil && affected == 0 {
		return fmt.Errorf("broadcast outbox %d was not in flight", id)
	}
	return nil
}

func (s *Store) MarkRetry(ctx context.Context, id int64, attempts int, cause error) (string, error) {
	if s == nil || s.db == nil {
		return "", fmt.Errorf("broadcast outbox database is unavailable")
	}
	status := OutboxRetryPending
	if attempts >= outboxMaxAttempts {
		status = OutboxDLQReplayable
	}
	reason := "broadcast failed"
	if cause != nil {
		reason = cause.Error()
	}
	if status == OutboxDLQReplayable {
		_, err := s.db.ExecContext(ctx, `
			UPDATE gps_broadcast_outbox
			SET status = ?, next_attempt_at = NULL, locked_until = NULL,
			    last_error = ?, updated_at = NOW()
			WHERE id = ? AND status = ?`, status, reason, id, OutboxInFlight)
		if err != nil {
			return "", fmt.Errorf("move broadcast outbox %d to DLQ: %w", id, err)
		}
		return status, nil
	}

	delay := RetryDelay(attempts)
	_, err := s.db.ExecContext(ctx, `
		UPDATE gps_broadcast_outbox
		SET status = ?, next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
		    locked_until = NULL, last_error = ?, updated_at = NOW()
		WHERE id = ? AND status = ?`, status, int(delay/time.Second), reason, id, OutboxInFlight)
	if err != nil {
		return "", fmt.Errorf("schedule broadcast outbox %d retry: %w", id, err)
	}
	return status, nil
}

// RetryDelay is intentionally bounded so a transient Reverb outage does not
// create an uncontrolled hot loop. Attempts are already persisted in SQL.
func RetryDelay(attempts int) time.Duration {
	if attempts < 1 {
		attempts = 1
	}
	delay := time.Second << uint(attempts-1)
	if delay > 5*time.Minute {
		return 5 * time.Minute
	}
	return delay
}

func (s *Store) Stats(ctx context.Context) (OutboxStats, error) {
	if s == nil || s.db == nil {
		return OutboxStats{}, fmt.Errorf("broadcast outbox database is unavailable")
	}
	var stats OutboxStats
	err := s.db.QueryRowContext(ctx, `
		SELECT
			COALESCE(SUM(status = ?), 0),
			COALESCE(SUM(status = ?), 0),
			COALESCE(SUM(status = ?), 0),
			COALESCE(SUM(status = ?), 0),
			COALESCE(SUM(status = ?), 0)
		FROM gps_broadcast_outbox`, OutboxPending, OutboxInFlight,
		OutboxRetryPending, OutboxSent, OutboxDLQReplayable).Scan(
		&stats.Pending, &stats.InFlight, &stats.Retry, &stats.Sent, &stats.DLQ)
	if err != nil {
		return OutboxStats{}, fmt.Errorf("read broadcast outbox stats: %w", err)
	}
	return stats, nil
}
