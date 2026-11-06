package storage

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"github.com/pistat-hamgit/gps-ingest/internal/broadcast"
	"github.com/pistat-hamgit/gps-ingest/internal/identity"
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
	GPSLogCode   string
	RawIngressID string
	PayloadHash  string
	Vendor       string
}

type Writer struct {
	db *sql.DB
}

// SameSecondConflictError is explicit and replayable. The raw receipt remains
// in the ledger, while no second physical GPS row is created for one logical
// device second until an owner policy resolves the conflict.
type SameSecondConflictError struct {
	GPSLogCode string
	EventID    string
	Committed  bool
}

func (e *SameSecondConflictError) Error() string {
	return fmt.Sprintf("iStartek SAME_SECOND_CONFLICT for gps_log_code=%s event_id=%s", e.GPSLogCode, e.EventID)
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
		EventID:    point.EventID,
		GPSLogCode: point.GPSLogCode,
		RawIngressID: point.RawIngressID,
		PayloadHash: point.RawPayloadHash,
		Vendor:     point.SourceProtocol,
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
	conflicts := make(map[string]struct{})
	claimedNew := make(map[string]struct{}, len(rows))
	var firstConflict *SameSecondConflictError

	for _, row := range rows {
		if row.GPSLogCode != "" {
			disposition, err := w.claimGPSLogIdentity(ctx, tx, row)
			if err != nil {
				return err
			}
			if disposition == "RETRANSMISSION" {
				continue
			}
			if disposition == "SAME_SECOND_CONFLICT" {
				conflicts[row.GPSLogCode] = struct{}{}
				if firstConflict == nil {
					firstConflict = &SameSecondConflictError{GPSLogCode: row.GPSLogCode, EventID: row.EventID}
				}
				continue
			}
			if disposition == "NEW" {
				if _, seen := claimedNew[row.GPSLogCode]; seen {
					// The identity is still PENDING because the first row in this
					// transaction has not reached the physical INSERT yet. Keep the
					// second raw receipt, but classify it as a retransmission.
					if err := w.recordGPSLogReceipt(ctx, tx, row, row.RawIngressID, logicalPayloadHash(row), "RETRANSMISSION", "same logical payload in one batch"); err != nil {
						return err
					}
					continue
				}
				claimedNew[row.GPSLogCode] = struct{}{}
			}
		}
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
			if row.GPSLogCode != "" {
				if _, updateErr := tx.ExecContext(ctx, `
					UPDATE gps_log_identities
					SET canonical_gps_data_id = ?, status = 'PERSISTED', last_seen_at = NOW(6), updated_at = NOW()
					WHERE gps_log_code = ?`, existingID, row.GPSLogCode); updateErr != nil {
					return fmt.Errorf("complete existing gps_log_code identity: %w", updateErr)
				}
			}
			continue
		}
		if existingErr != sql.ErrNoRows {
			return fmt.Errorf("check duplicate row: %w", existingErr)
		}
		result, err := tx.ExecContext(ctx, `
			INSERT INTO gps_data
				(tractor_id, coordinate, speed, status, directions, imei, date_time, event_id, gps_log_code, raw_ingress_id)
			VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''))`, row.TractorID, row.Coordinate, row.Speed,
			row.Status, row.Directions, row.IMEI, row.DateTime, row.EventID, row.GPSLogCode, row.RawIngressID)
		if err != nil {
			return fmt.Errorf("insert batch: %w", err)
		}
		if row.GPSLogCode != "" {
			id, idErr := result.LastInsertId()
			if idErr != nil {
				return fmt.Errorf("read gps_data id for gps_log_code: %w", idErr)
			}
			if _, updateErr := tx.ExecContext(ctx, `
				UPDATE gps_log_identities
				SET canonical_gps_data_id = ?, status = 'PERSISTED', last_seen_at = NOW(6), updated_at = NOW()
				WHERE gps_log_code = ?`, id, row.GPSLogCode); updateErr != nil {
				return fmt.Errorf("complete gps_log_code identity: %w", updateErr)
			}
		}
	}

	for _, outboxRow := range outboxRows {
		if _, conflict := conflicts[outboxRow.GPSLogCode]; conflict {
			continue
		}
		if err := broadcast.NewStore(w.db).InsertTx(ctx, tx, outboxRow); err != nil {
			return fmt.Errorf("persist broadcast outbox: %w", err)
		}
	}

	if err := tx.Commit(); err != nil {
		return fmt.Errorf("commit batch: %w", err)
	}
	if firstConflict != nil {
		firstConflict.Committed = true
		return firstConflict
	}

	return nil
}

// claimGPSLogIdentity serializes one logical iStartek position per device
// second. It records every raw receipt and only suppresses exact payload
// retransmissions. Different payloads become an auditable conflict.
func (w *Writer) claimGPSLogIdentity(ctx context.Context, tx *sql.Tx, row Row) (string, error) {
	imei, eventUTC, err := identity.ParseGPSLogCode(row.GPSLogCode)
	if err != nil || imei != strings.TrimSpace(row.IMEI) {
		return "", fmt.Errorf("invalid gps_log_code contract for event %s", row.EventID)
	}
	// The raw HTTP/Gateway hash is retained on the ledger, but logical
	// retransmission identity is based on the position payload. A retry may
	// legitimately have a different envelope/event_id while representing the
	// same GPS fix.
	payloadHash := logicalPayloadHash(row)
	rawIngressID := row.RawIngressID
	if rawIngressID == "" {
		rawIngressID = "event:" + row.EventID
	}
	if _, err := tx.ExecContext(ctx, `
		INSERT INTO gps_log_identities
			(gps_log_code, vendor, imei, device_event_utc, existing_event_id,
			 canonical_payload_hash, status, first_seen_at, last_seen_at, created_at, updated_at)
		VALUES (?, '01', ?, ?, NULLIF(?, ''), ?, 'PENDING', NOW(6), NOW(6), NOW(), NOW())
		ON DUPLICATE KEY UPDATE last_seen_at = NOW(6), updated_at = NOW()`,
		row.GPSLogCode, imei, eventUTC, row.EventID, payloadHash); err != nil {
		return "", fmt.Errorf("reserve gps_log_code: %w", err)
	}
	var canonicalHash, status string
	var canonicalGPSDataID sql.NullInt64
	if err := tx.QueryRowContext(ctx, `
		SELECT COALESCE(canonical_payload_hash, ''), status, canonical_gps_data_id
		FROM gps_log_identities WHERE gps_log_code = ? FOR UPDATE`, row.GPSLogCode).Scan(&canonicalHash, &status, &canonicalGPSDataID); err != nil {
		return "", fmt.Errorf("read gps_log_code identity: %w", err)
	}
	if status == "SAME_SECOND_CONFLICT" || (canonicalHash != "" && canonicalHash != payloadHash) {
		if err := w.recordGPSLogReceipt(ctx, tx, row, rawIngressID, payloadHash, "SAME_SECOND_CONFLICT", "payload hash differs for one IMEI and UTC second"); err != nil {
			return "", err
		}
		if _, err := tx.ExecContext(ctx, `UPDATE gps_log_identities SET status = 'SAME_SECOND_CONFLICT', updated_at = NOW() WHERE gps_log_code = ?`, row.GPSLogCode); err != nil {
			return "", fmt.Errorf("mark gps_log_code conflict: %w", err)
		}
		return "SAME_SECOND_CONFLICT", nil
	}
	if canonicalHash != "" && (status == "PERSISTED" || canonicalGPSDataID.Valid) {
		if err := w.recordGPSLogReceipt(ctx, tx, row, rawIngressID, payloadHash, "RETRANSMISSION", "same logical payload"); err != nil {
			return "", err
		}
		return "RETRANSMISSION", nil
	}
	if err := w.recordGPSLogReceipt(ctx, tx, row, rawIngressID, payloadHash, "FIRST", ""); err != nil {
		return "", err
	}
	return "NEW", nil
}

func (w *Writer) recordGPSLogReceipt(ctx context.Context, tx *sql.Tx, row Row, rawIngressID, payloadHash, status, reason string) error {
	_, err := tx.ExecContext(ctx, `
		INSERT INTO gps_log_receipts
			(raw_ingress_id, gps_log_code, existing_event_id, payload_hash, deduplication_status, conflict_reason, created_at, updated_at)
		VALUES (?, ?, NULLIF(?, ''), ?, ?, NULLIF(?, ''), NOW(), NOW())
		ON DUPLICATE KEY UPDATE deduplication_status = VALUES(deduplication_status), conflict_reason = VALUES(conflict_reason), updated_at = NOW()`,
		rawIngressID, row.GPSLogCode, row.EventID, payloadHash, status, reason)
	if err != nil {
		return fmt.Errorf("record gps_log receipt: %w", err)
	}
	return nil
}

func logicalPayloadHash(row Row) string {
	raw := fmt.Sprintf("%s|%s|%d|%d|%s|%s", row.IMEI, row.DateTime, row.Speed, row.Status, row.Coordinate, row.Directions)
	sum := sha256.Sum256([]byte(raw))
	return hex.EncodeToString(sum[:])
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
